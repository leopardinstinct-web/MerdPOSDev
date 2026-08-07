part of merdpos_staff;

typedef PosProductSearch = Future<List<RetailProduct>> Function(String query);
typedef PosBarcodeLookup = Future<RetailProduct?> Function(String barcode);
typedef PosHealthLookup = Future<RetailSyncHealth> Function();
typedef PosCatalogueHealthLookup = Future<CatalogueSyncHealth> Function();

class PosPage extends StatefulWidget {
  const PosPage({
    super.key,
    required this.session,
    required this.cashier,
    this.productSearch,
    this.barcodeLookup,
    this.healthLookup,
    this.catalogueHealthLookup,
  });
  final AppSession session;
  final Employee cashier;
  final PosProductSearch? productSearch;
  final PosBarcodeLookup? barcodeLookup;
  final PosHealthLookup? healthLookup;
  final PosCatalogueHealthLookup? catalogueHealthLookup;

  @override
  State<PosPage> createState() => _PosPageState();
}

class _PosPageState extends State<PosPage> {
  final TextEditingController _search = TextEditingController();
  final FocusNode _scannerFocus = FocusNode(debugLabel: 'POS scanner');
  final ScannerInputBuffer _scanner = ScannerInputBuffer();
  final PosBasket _basket = PosBasket();
  List<RetailProduct> _products = <RetailProduct>[];
  RetailSyncHealth? _health;
  CatalogueSyncHealth? _catalogueHealth;
  bool _loading = true;
  bool _checkingOut = false;
  String _scannerStatus = 'Scanner ready';
  bool _scannerError = false;

  @override
  void initState() {
    super.initState();
    unawaited(_load());
    WidgetsBinding.instance.addPostFrameCallback((_) => _recoverScannerFocus());
  }

  @override
  void dispose() {
    _search.dispose();
    _scannerFocus.dispose();
    super.dispose();
  }

  Future<void> _load([String q = '']) async {
    if (mounted) setState(() => _loading = true);
    final List<Object> results = await Future.wait<Object>(<Future<Object>>[
      widget.productSearch?.call(q) ?? RetailDb.searchProducts(q),
      widget.healthLookup?.call() ?? RetailDb.syncHealth(),
      widget.catalogueHealthLookup?.call() ?? CatalogueSync.health(),
    ]);
    if (!mounted) return;
    setState(() {
      _products = results[0] as List<RetailProduct>;
      _health = results[1] as RetailSyncHealth;
      _catalogueHealth = results[2] as CatalogueSyncHealth;
      _loading = false;
    });
  }

  void _recoverScannerFocus() {
    if (mounted && !_scannerFocus.hasFocus) _scannerFocus.requestFocus();
  }

  KeyEventResult _onKey(KeyEvent event) {
    if (event is! KeyDownEvent) return KeyEventResult.ignored;
    if (event.logicalKey == LogicalKeyboardKey.enter ||
        event.logicalKey == LogicalKeyboardKey.numpadEnter) {
      final String? barcode = _scanner.add('\n', DateTime.now().toUtc());
      if (barcode != null) unawaited(_scan(barcode));
      return KeyEventResult.handled;
    }
    final String? character = event.character;
    if (character != null &&
        character.isNotEmpty &&
        !HardwareKeyboard.instance.isControlPressed &&
        !HardwareKeyboard.instance.isMetaPressed) {
      _scanner.add(character, DateTime.now().toUtc());
      return KeyEventResult.handled;
    }
    return KeyEventResult.ignored;
  }

  Future<void> _scan(String barcode) async {
    final RetailProduct? product =
        await (widget.barcodeLookup?.call(barcode) ??
            RetailDb.productByExactBarcode(barcode));
    if (!mounted) return;
    if (product == null) {
      _setScannerStatus('Unknown barcode: $barcode', error: true);
      return;
    }
    try {
      setState(() => _basket.add(product, barcodeUsed: barcode));
      _setScannerStatus('${product.name} added', error: false);
    } on BasketValidationException catch (error) {
      _setScannerStatus(error.message, error: true);
    }
  }

  void _setScannerStatus(String message, {required bool error}) {
    if (!mounted) return;
    setState(() {
      _scannerStatus = message;
      _scannerError = error;
    });
    _recoverScannerFocus();
  }

  Future<void> _manualBarcode() async {
    String entered = '';
    final String? barcode = await showDialog<String>(
      context: context,
      builder: (BuildContext context) => AlertDialog(
        title: const Text('Enter barcode'),
        content: TextField(
          key: const Key('manual-barcode-field'),
          autofocus: true,
          textInputAction: TextInputAction.done,
          onChanged: (value) => entered = value,
          onSubmitted: (value) => Navigator.pop(context, value),
          decoration: const InputDecoration(hintText: 'Exact barcode text'),
        ),
        actions: <Widget>[
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, entered),
            child: const Text('Add'),
          ),
        ],
      ),
    );
    _recoverScannerFocus();
    if (barcode != null && barcode.isNotEmpty) await _scan(barcode);
  }

  void _addProduct(RetailProduct product) {
    try {
      setState(() => _basket.add(product, barcodeUsed: product.barcode));
      _setScannerStatus('${product.name} added', error: false);
    } on BasketValidationException catch (error) {
      _setScannerStatus(error.message, error: true);
    }
  }

  Future<void> _editQuantity(int index) async {
    final BasketLine line = _basket.lines[index];
    String entered = _quantityText(line);
    final String? value = await showDialog<String>(
      context: context,
      builder: (BuildContext context) => AlertDialog(
        title: Text('Quantity • ${line.product.name}'),
        content: TextFormField(
          key: const Key('quantity-field'),
          initialValue: entered,
          autofocus: true,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          onChanged: (text) => entered = text,
          onFieldSubmitted: (text) => Navigator.pop(context, text),
          decoration: InputDecoration(suffixText: line.product.unitOfMeasure),
        ),
        actions: <Widget>[
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, entered),
            child: const Text('Apply'),
          ),
        ],
      ),
    );
    _recoverScannerFocus();
    if (value == null) return;
    final double? parsed = double.tryParse(value);
    if (parsed == null) {
      _setScannerStatus('Enter a valid quantity.', error: true);
      return;
    }
    try {
      setState(() => _basket.setQuantity(index, parsed));
    } on BasketValidationException catch (error) {
      _setScannerStatus(error.message, error: true);
    }
  }

  Future<void> _checkout(String method) async {
    if (_basket.lines.isEmpty) return;
    setState(() => _checkingOut = true);
    try {
      await RetailDb.completeSale(
        session: widget.session,
        cashier: widget.cashier,
        lines: _basket.lines,
        paymentMethod: method,
      );
      if (!mounted) return;
      setState(() => _basket.clear());
      await _load(_search.text);
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text('Sale completed • $method')));
    } catch (error) {
      if (mounted)
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(cleanError(error))));
    } finally {
      if (mounted) setState(() => _checkingOut = false);
      _recoverScannerFocus();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Focus(
      focusNode: _scannerFocus,
      autofocus: true,
      onKeyEvent: (_, event) => _onKey(event),
      child: GestureDetector(
        behavior: HitTestBehavior.translucent,
        onTap: _recoverScannerFocus,
        child: ColoredBox(
          color: const Color(0xFFF4F6F8),
          child: LayoutBuilder(
            builder: (context, constraints) {
              final bool compact = constraints.maxWidth < 1050;
              return compact
                  ? Column(
                      children: <Widget>[
                        Expanded(child: _cataloguePane()),
                        SizedBox(height: 330, child: _basketPane()),
                      ],
                    )
                  : Row(
                      children: <Widget>[
                        Expanded(flex: 3, child: _cataloguePane()),
                        SizedBox(width: 410, child: _basketPane()),
                      ],
                    );
            },
          ),
        ),
      ),
    );
  }

  Widget _cataloguePane() => Padding(
    padding: const EdgeInsets.all(24),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Row(
          children: <Widget>[
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  const Text(
                    'Point of sale',
                    style: TextStyle(
                      color: _PosColors.ink,
                      fontSize: 26,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    '${widget.session.storeName}  •  ${widget.cashier.fullName}  •  ${widget.session.deviceUuid}',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(color: _PosColors.muted),
                  ),
                ],
              ),
            ),
            _StatusPill(
              label: _catalogueHealth?.stale == true
                  ? 'Catalogue stale • offline ready'
                  : _health?.needsAttention == true
                  ? 'Sync attention'
                  : 'Catalogue available',
              warning:
                  _catalogueHealth?.stale == true ||
                  _health?.needsAttention == true,
            ),
          ],
        ),
        const SizedBox(height: 18),
        Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: _scannerError
                ? _PosColors.errorSoft
                : _PosColors.successSoft,
            borderRadius: BorderRadius.circular(12),
            border: Border.all(
              color: _scannerError ? _PosColors.error : _PosColors.success,
            ),
          ),
          child: Row(
            children: <Widget>[
              Icon(
                _scannerError ? Icons.error_outline : Icons.qr_code_scanner,
                color: _scannerError ? _PosColors.error : _PosColors.success,
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  _scannerStatus,
                  key: const Key('scanner-status'),
                  style: TextStyle(
                    color: _scannerError ? _PosColors.error : _PosColors.ink,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
              OutlinedButton.icon(
                onPressed: _manualBarcode,
                icon: const Icon(Icons.keyboard),
                label: const Text('Enter barcode'),
              ),
            ],
          ),
        ),
        const SizedBox(height: 14),
        TextField(
          controller: _search,
          onChanged: _load,
          style: const TextStyle(color: _PosColors.ink),
          decoration: const InputDecoration(
            prefixIcon: Icon(Icons.search),
            labelText: 'Search products, SKU or barcode',
            fillColor: Colors.white,
          ),
        ),
        const SizedBox(height: 14),
        Expanded(
          child: _loading
              ? const Center(child: CircularProgressIndicator())
              : GridView.builder(
                  gridDelegate: const SliverGridDelegateWithMaxCrossAxisExtent(
                    maxCrossAxisExtent: 230,
                    mainAxisExtent: 158,
                    crossAxisSpacing: 12,
                    mainAxisSpacing: 12,
                  ),
                  itemCount: _products.length,
                  itemBuilder: (_, int index) => _ProductTile(
                    product: _products[index],
                    onTap: () => _addProduct(_products[index]),
                  ),
                ),
        ),
      ],
    ),
  );

  Widget _basketPane() => ColoredBox(
    color: Colors.white,
    child: SafeArea(
      child: Column(
        children: <Widget>[
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 20, 12, 16),
            child: Row(
              children: <Widget>[
                const Icon(
                  Icons.shopping_basket_outlined,
                  color: _PosColors.brand,
                ),
                const SizedBox(width: 10),
                const Text(
                  'Current sale',
                  style: TextStyle(
                    color: _PosColors.ink,
                    fontSize: 19,
                    fontWeight: FontWeight.w800,
                  ),
                ),
                const Spacer(),
                Text(
                  '${_basket.lines.length} lines',
                  style: const TextStyle(color: _PosColors.muted),
                ),
              ],
            ),
          ),
          const Divider(height: 1, color: _PosColors.border),
          Expanded(
            child: _basket.lines.isEmpty
                ? const Center(
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: <Widget>[
                        Icon(
                          Icons.qr_code_scanner,
                          size: 44,
                          color: _PosColors.muted,
                        ),
                        SizedBox(height: 10),
                        Text(
                          'Scan an item to begin',
                          style: TextStyle(
                            color: _PosColors.ink,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                        Text(
                          'Scanner ready • Enter terminator',
                          style: TextStyle(color: _PosColors.muted),
                        ),
                      ],
                    ),
                  )
                : ListView.separated(
                    itemCount: _basket.lines.length,
                    separatorBuilder: (_, __) =>
                        const Divider(height: 1, color: _PosColors.border),
                    itemBuilder: (_, int index) => _basketLine(index),
                  ),
          ),
          const Divider(height: 1, color: _PosColors.border),
          Padding(
            padding: const EdgeInsets.all(20),
            child: Column(
              children: <Widget>[
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: <Widget>[
                    const Text(
                      'TOTAL',
                      style: TextStyle(
                        color: _PosColors.muted,
                        fontWeight: FontWeight.w800,
                        letterSpacing: 1.2,
                      ),
                    ),
                    Text(
                      '\$${_basket.totalExact}',
                      key: const Key('basket-total'),
                      style: const TextStyle(
                        color: _PosColors.ink,
                        fontSize: 32,
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 16),
                Row(
                  children: <Widget>[
                    Expanded(
                      child: FilledButton.icon(
                        onPressed: _checkingOut || _basket.lines.isEmpty
                            ? null
                            : () => _checkout('cash'),
                        icon: const Icon(Icons.payments_outlined),
                        label: const Text('Cash'),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: FilledButton.icon(
                        onPressed: _checkingOut || _basket.lines.isEmpty
                            ? null
                            : () => _checkout('card'),
                        icon: const Icon(Icons.credit_card),
                        label: const Text('Card'),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ],
      ),
    ),
  );

  Widget _basketLine(int index) {
    final BasketLine line = _basket.lines[index];
    final double projected = _basket.projectedStock(line);
    final bool insufficient = _basket.insufficientStock(line);
    final bool low = _basket.lowStock(line);
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 14, 8, 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    Text(
                      line.product.name,
                      style: const TextStyle(
                        color: _PosColors.ink,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      '${line.barcodeUsed.isEmpty ? 'No barcode' : line.barcodeUsed}  •  ${line.product.unitOfMeasure}  •  \$${line.product.priceExact ?? line.product.price.toStringAsFixed(2)}  •  ${line.product.taxCode == 'NO_TAX' ? 'NO_TAX' : '${line.product.taxCode} tax inclusive'}',
                      style: const TextStyle(
                        color: _PosColors.muted,
                        fontSize: 12,
                      ),
                    ),
                    if (line.product.promotionName != null)
                      Text(
                        line.product.promotionName!,
                        style: const TextStyle(
                          color: _PosColors.brand,
                          fontSize: 12,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                  ],
                ),
              ),
              IconButton(
                key: Key('remove-line-$index'),
                tooltip: 'Remove line',
                onPressed: () => setState(() => _basket.removeAt(index)),
                icon: const Icon(Icons.delete_outline, color: _PosColors.error),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Row(
            children: <Widget>[
              OutlinedButton.icon(
                onPressed: () => _editQuantity(index),
                icon: const Icon(Icons.edit, size: 17),
                label: Text(_quantityText(line)),
              ),
              const SizedBox(width: 8),
              IconButton(
                onPressed: () {
                  try {
                    setState(
                      () => _basket.setQuantity(index, line.quantity + 1),
                    );
                  } on BasketValidationException catch (error) {
                    _setScannerStatus(error.message, error: true);
                  }
                },
                icon: const Icon(Icons.add_circle_outline),
                tooltip: 'Add one',
              ),
              const Spacer(),
              Text(
                '\$${_basket.lineTotalExact(line)}',
                style: const TextStyle(
                  color: _PosColors.ink,
                  fontSize: 18,
                  fontWeight: FontWeight.w900,
                ),
              ),
            ],
          ),
          Text(
            'Projected stock ${projected.toStringAsFixed(line.product.unitOfMeasure == 'each' ? 0 : 3)}${insufficient
                ? ' • insufficient'
                : low
                ? ' • low'
                : ''}',
            style: TextStyle(
              color: insufficient
                  ? _PosColors.error
                  : low
                  ? _PosColors.warning
                  : _PosColors.muted,
              fontSize: 12,
              fontWeight: insufficient || low
                  ? FontWeight.w700
                  : FontWeight.w500,
            ),
          ),
        ],
      ),
    );
  }

  String _quantityText(BasketLine line) => line.product.unitOfMeasure == 'each'
      ? line.quantity.toStringAsFixed(0)
      : line.quantity
            .toStringAsFixed(3)
            .replaceFirst(RegExp(r'0+$'), '')
            .replaceFirst(RegExp(r'\.$'), '');
}

class _ProductTile extends StatelessWidget {
  const _ProductTile({required this.product, required this.onTap});
  final RetailProduct product;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Material(
    color: Colors.white,
    borderRadius: BorderRadius.circular(12),
    child: InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(12),
      child: Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: _PosColors.border),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Text(
              product.name,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(
                color: _PosColors.ink,
                fontWeight: FontWeight.w800,
              ),
            ),
            const SizedBox(height: 4),
            Text(
              product.category,
              style: const TextStyle(color: _PosColors.muted, fontSize: 12),
            ),
            const Spacer(),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: <Widget>[
                Text(
                  '\$${product.priceExact ?? product.price.toStringAsFixed(2)}',
                  style: const TextStyle(
                    color: _PosColors.brand,
                    fontSize: 18,
                    fontWeight: FontWeight.w900,
                  ),
                ),
                Text(
                  '${product.stock.toStringAsFixed(product.unitOfMeasure == 'each' ? 0 : 3)} ${product.unitOfMeasure}',
                  style: const TextStyle(color: _PosColors.muted, fontSize: 11),
                ),
              ],
            ),
          ],
        ),
      ),
    ),
  );
}

class _StatusPill extends StatelessWidget {
  const _StatusPill({required this.label, required this.warning});
  final String label;
  final bool warning;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
    decoration: BoxDecoration(
      color: warning ? _PosColors.warningSoft : _PosColors.successSoft,
      borderRadius: BorderRadius.circular(99),
    ),
    child: Text(
      label,
      style: TextStyle(
        color: warning ? _PosColors.warning : _PosColors.success,
        fontWeight: FontWeight.w800,
      ),
    ),
  );
}

class _PosColors {
  static const Color ink = Color(0xFF152033);
  static const Color muted = Color(0xFF64748B);
  static const Color border = Color(0xFFD9E0E8);
  static const Color brand = Color(0xFF176B87);
  static const Color success = Color(0xFF19705A);
  static const Color successSoft = Color(0xFFE8F5F0);
  static const Color warning = Color(0xFF9A5A00);
  static const Color warningSoft = Color(0xFFFFF3D6);
  static const Color error = Color(0xFFB42318);
  static const Color errorSoft = Color(0xFFFFECEA);
}
