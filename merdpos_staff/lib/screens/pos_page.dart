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
  List<RetailProduct> _catalogue = <RetailProduct>[];
  RetailSyncHealth? _health;
  CatalogueSyncHealth? _catalogueHealth;
  String? _selectedCategory;
  bool _loading = true;
  bool _checkingOut = false;
  TenderPlan? _pendingTenderPlan;
  String? _pendingCheckoutUid;
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

  Future<void> _load() async {
    if (mounted) setState(() => _loading = true);
    final List<Object> results = await Future.wait<Object>(<Future<Object>>[
      widget.productSearch?.call('') ?? RetailDb.posCatalogueProducts(),
      widget.healthLookup?.call() ?? RetailDb.syncHealth(),
      widget.catalogueHealthLookup?.call() ?? CatalogueSync.health(),
    ]);
    if (!mounted) return;
    setState(() {
      _catalogue = results[0] as List<RetailProduct>;
      _health = results[1] as RetailSyncHealth;
      _catalogueHealth = results[2] as CatalogueSyncHealth;
      _loading = false;
    });
  }

  List<String> get _categories {
    final Set<String> values = _catalogue
        .map((product) => product.category.trim())
        .where((category) => category.isNotEmpty)
        .toSet();
    final List<String> sorted = values.toList()
      ..sort((a, b) => a.toLowerCase().compareTo(b.toLowerCase()));
    return sorted;
  }

  List<RetailProduct> get _visibleProducts {
    final String query = _search.text.trim().toLowerCase();
    return _catalogue
        .where((product) {
          if (_selectedCategory != null &&
              product.category != _selectedCategory)
            return false;
          if (query.isEmpty) return true;
          return product.name.toLowerCase().contains(query) ||
              (product.sku?.toLowerCase().contains(query) ?? false) ||
              product.barcode.toLowerCase().contains(query) ||
              product.barcodeAliases.any(
                (alias) => alias.toLowerCase().contains(query),
              );
        })
        .toList(growable: false);
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
    _addProduct(product, barcodeUsed: barcode);
  }

  void _addProduct(RetailProduct product, {String? barcodeUsed}) {
    try {
      setState(
        () => _basket.add(product, barcodeUsed: barcodeUsed ?? product.barcode),
      );
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
      builder: (context) => AlertDialog(
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

  Future<void> _editQuantity(int index) async {
    final BasketLine line = _basket.lines[index];
    String entered = _quantityText(line);
    final String? value = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
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

  Future<void> _clearBasket() async {
    if (_basket.lines.isEmpty) {
      _recoverScannerFocus();
      return;
    }
    final bool confirmed =
        await showDialog<bool>(
          context: context,
          builder: (context) => AlertDialog(
            title: const Text('Clear current order?'),
            content: const Text(
              'This removes all items from the in-progress basket only.',
            ),
            actions: <Widget>[
              TextButton(
                onPressed: () => Navigator.pop(context, false),
                child: const Text('Keep items'),
              ),
              FilledButton(
                key: const Key('confirm-clear-basket'),
                onPressed: () => Navigator.pop(context, true),
                style: FilledButton.styleFrom(
                  backgroundColor: _PosColors.error,
                ),
                child: const Text('Clear basket'),
              ),
            ],
          ),
        ) ??
        false;
    if (confirmed && mounted) setState(_basket.clear);
    _recoverScannerFocus();
  }

  Future<void> _checkout(CheckoutTenderMode mode) async {
    if (_basket.lines.isEmpty || _checkingOut) return;
    final CheckoutAmounts amounts;
    try {
      amounts = CheckoutAmounts.fromBasket(_basket);
    } catch (error) {
      _setScannerStatus(cleanError(error), error: true);
      return;
    }
    final TenderPlan? tenderPlan = await showDialog<TenderPlan>(
      context: context,
      barrierDismissible: false,
      builder: (_) => CheckoutTenderDialog(
        totalCents: amounts.totalCents,
        initialMode: mode,
        initialPlan: _pendingTenderPlan?.totalCents == amounts.totalCents
            ? _pendingTenderPlan
            : null,
      ),
    );
    _recoverScannerFocus();
    if (tenderPlan == null || !mounted) return;
    setState(() {
      _checkingOut = true;
      _pendingTenderPlan = tenderPlan;
      _pendingCheckoutUid ??= const Uuid().v4();
    });
    try {
      final CheckoutCommitResult result = await RetailDb.completeCheckout(
        session: widget.session,
        cashier: widget.cashier,
        lines: _basket.lines,
        tenderPlan: tenderPlan,
        saleUid: _pendingCheckoutUid,
      );
      if (!mounted) return;
      await _showCheckoutOutcome(result);
      if (!mounted) return;
      setState(() {
        _basket.clear();
        _pendingTenderPlan = null;
        _pendingCheckoutUid = null;
      });
      await _load();
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

  Future<void> _showCheckoutOutcome(CheckoutCommitResult result) async {
    await showDialog<void>(
      context: context,
      barrierDismissible: false,
      builder: (context) => AlertDialog(
        title: const Row(
          children: <Widget>[
            Icon(Icons.check_circle, color: _PosColors.success),
            SizedBox(width: 10),
            Text('Sale completed'),
          ],
        ),
        content: SizedBox(
          width: 430,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Text(
                result.saleNumber,
                style: const TextStyle(
                  fontWeight: FontWeight.w900,
                  fontSize: 20,
                ),
              ),
              const SizedBox(height: 12),
              ...result.tenders.map(
                (component) => ListTile(
                  dense: true,
                  contentPadding: EdgeInsets.zero,
                  title: Text(
                    component.type == TenderType.cash
                        ? 'Cash'
                        : 'Card recorded',
                  ),
                  trailing: Text('\$${centsToMoney(component.amountCents)}'),
                  subtitle: component.changeCents > 0
                      ? Text('Change \$${centsToMoney(component.changeCents)}')
                      : null,
                ),
              ),
              const Divider(),
              Text(
                'Total \$${centsToMoney(result.totalCents)}',
                style: const TextStyle(
                  fontSize: 22,
                  fontWeight: FontWeight.w900,
                ),
              ),
              const SizedBox(height: 8),
              const Text(
                'Saved locally • pending synchronization',
                style: TextStyle(
                  color: _PosColors.warning,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ],
          ),
        ),
        actions: <Widget>[
          FilledButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('Next order'),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) => Focus(
    focusNode: _scannerFocus,
    autofocus: true,
    onKeyEvent: (_, event) => _onKey(event),
    child: GestureDetector(
      behavior: HitTestBehavior.translucent,
      onTap: _recoverScannerFocus,
      child: ColoredBox(
        color: _PosColors.workspace,
        child: LayoutBuilder(
          builder: (context, constraints) {
            if (constraints.maxWidth < 980) return _compactLayout();
            final double orderWidth = (constraints.maxWidth * .34).clamp(
              350,
              460,
            );
            return Row(
              children: <Widget>[
                SizedBox(width: orderWidth, child: _orderPanel()),
                const VerticalDivider(width: 1, color: _PosColors.border),
                SizedBox(width: 150, child: _categoryRail()),
                const VerticalDivider(width: 1, color: _PosColors.border),
                Expanded(child: _productsPanel()),
              ],
            );
          },
        ),
      ),
    ),
  );

  Widget _compactLayout() => Column(
    children: <Widget>[
      Expanded(child: _productsPanel()),
      SizedBox(height: 360, child: _orderPanel()),
    ],
  );

  Widget _orderPanel() => PosCurrentOrderPanel(
    basket: _basket,
    checkingOut: _checkingOut,
    onEditQuantity: _editQuantity,
    onRemoveLine: (index) => setState(() => _basket.removeAt(index)),
    onIncrement: (index) {
      try {
        setState(
          () => _basket.setQuantity(index, _basket.lines[index].quantity + 1),
        );
      } on BasketValidationException catch (error) {
        _setScannerStatus(error.message, error: true);
      }
    },
    onClear: _clearBasket,
    onCash: () => _checkout(CheckoutTenderMode.cash),
    onCard: () => _checkout(CheckoutTenderMode.card),
    onSplit: () => _checkout(CheckoutTenderMode.split),
    quantityText: _quantityText,
  );

  Widget _categoryRail() => PosCategoryRail(
    categories: _categories,
    selectedCategory: _selectedCategory,
    onSelected: (category) {
      setState(() => _selectedCategory = category);
      _recoverScannerFocus();
    },
  );

  Widget _productsPanel() => PosProductGrid(
    loading: _loading,
    products: _visibleProducts,
    searchController: _search,
    selectedCategory: _selectedCategory,
    scannerStatus: _scannerStatus,
    scannerError: _scannerError,
    catalogueStatus: _catalogueHealth?.stale == true
        ? 'Catalogue stale • offline ready'
        : _health?.needsAttention == true
        ? 'Sync attention'
        : 'Catalogue available',
    catalogueWarning:
        _catalogueHealth?.stale == true || _health?.needsAttention == true,
    storeContext:
        '${widget.session.storeName} • ${widget.cashier.fullName} • ${widget.session.deviceUuid}',
    onSearchChanged: (_) => setState(() {}),
    onSearchSubmitted: (_) => WidgetsBinding.instance.addPostFrameCallback(
      (_) => _recoverScannerFocus(),
    ),
    onClearSearch: () {
      _search.clear();
      setState(() {});
      _recoverScannerFocus();
    },
    onManualBarcode: _manualBarcode,
    onProductTap: _addProduct,
  );

  String _quantityText(BasketLine line) => line.product.unitOfMeasure == 'each'
      ? line.quantity.toStringAsFixed(0)
      : line.quantity
            .toStringAsFixed(3)
            .replaceFirst(RegExp(r'0+$'), '')
            .replaceFirst(RegExp(r'\.$'), '');
}

class _PosColors {
  static const Color workspace = Color(0xFFF4F6F8);
  static const Color ink = Color(0xFF152033);
  static const Color muted = Color(0xFF64748B);
  static const Color border = Color(0xFFD9E0E8);
  static const Color brand = Color(0xFF176B87);
  static const Color brandSoft = Color(0xFFE8F3F7);
  static const Color success = Color(0xFF19705A);
  static const Color successSoft = Color(0xFFE8F5F0);
  static const Color warning = Color(0xFF9A5A00);
  static const Color warningSoft = Color(0xFFFFF3D6);
  static const Color error = Color(0xFFB42318);
  static const Color errorSoft = Color(0xFFFFECEA);
}
