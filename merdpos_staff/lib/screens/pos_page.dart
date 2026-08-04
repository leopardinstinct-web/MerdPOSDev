part of merdpos_staff;

class PosPage extends StatefulWidget {
  const PosPage({super.key, required this.session, required this.cashier});
  final AppSession session;
  final Employee cashier;

  @override
  State<PosPage> createState() => _PosPageState();
}

class _PosPageState extends State<PosPage> {
  final _search = TextEditingController();
  final List<BasketLine> _basket = [];
  List<RetailProduct> _products = [];
  bool _loading = true;
  bool _checkingOut = false;

  @override
  void initState() { super.initState(); unawaited(_load()); }
  @override
  void dispose() { _search.dispose(); super.dispose(); }

  Future<void> _load([String q = '']) async {
    setState(() => _loading = true);
    final products = await RetailDb.searchProducts(q);
    if (!mounted) return;
    setState(() { _products = products; _loading = false; });
  }

  void _add(RetailProduct product) {
    final i = _basket.indexWhere((x) => x.product.id == product.id);
    setState(() {
      if (i >= 0) {
        if (_basket[i].quantity < product.stock) _basket[i].quantity += 1;
      } else if (product.stock > 0) {
        _basket.add(BasketLine(product: product));
      }
    });
  }

  double get _total => _basket.fold(0, (sum, line) => sum + line.total);

  Future<void> _checkout(String method) async {
    if (_basket.isEmpty) return;
    setState(() => _checkingOut = true);
    try {
      await RetailDb.completeSale(session: widget.session, cashier: widget.cashier, lines: _basket, paymentMethod: method);
      if (!mounted) return;
      setState(() => _basket.clear());
      await _load(_search.text);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Sale completed • $method')));
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(cleanError(e))));
    } finally {
      if (mounted) setState(() => _checkingOut = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Row(children: [
      Expanded(flex: 3, child: Padding(padding: const EdgeInsets.all(24), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        _ScreenHeader(storeName: widget.session.storeName, title: 'POS', subtitle: 'Cashier: ${widget.cashier.fullName}'),
        const SizedBox(height: 16),
        TextField(controller: _search, onChanged: _load, autofocus: true,
          decoration: const InputDecoration(prefixIcon: Icon(Icons.search), labelText: 'Scan barcode or search products')),
        const SizedBox(height: 16),
        Expanded(child: _loading ? const Center(child: CircularProgressIndicator()) : GridView.builder(
          gridDelegate: const SliverGridDelegateWithMaxCrossAxisExtent(maxCrossAxisExtent: 220, mainAxisExtent: 150, crossAxisSpacing: 12, mainAxisSpacing: 12),
          itemCount: _products.length,
          itemBuilder: (_, i) { final product = _products[i]; return InkWell(onTap: () => _add(product), borderRadius: BorderRadius.circular(10), child: Card(child: Padding(padding: const EdgeInsets.all(12), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(product.name, maxLines: 2, overflow: TextOverflow.ellipsis, style: Theme.of(context).textTheme.titleMedium),
            const Spacer(), Text(product.category, style: Theme.of(context).textTheme.bodySmall),
            const SizedBox(height: 4), Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [Text('\$${product.price.toStringAsFixed(2)}', style: const TextStyle(color: BlueIce.accent, fontWeight: FontWeight.w700)), Text('${product.stock.toStringAsFixed(0)} in stock', style: Theme.of(context).textTheme.bodySmall)]),
          ])))); },
        )),
      ]))),
      Container(width: 360, decoration: const BoxDecoration(color: BlueIce.surface, border: Border(left: BorderSide(color: BlueIce.border))), child: SafeArea(child: Column(children: [
        Padding(padding: const EdgeInsets.all(16), child: Row(children: [const Icon(Icons.shopping_cart_outlined, color: BlueIce.accent), const SizedBox(width: 8), Text('Current sale', style: Theme.of(context).textTheme.titleMedium), const Spacer(), Text('${_basket.length} lines', style: Theme.of(context).textTheme.bodySmall)])),
        const Divider(height: 1),
        Expanded(child: _basket.isEmpty ? const Center(child: Text('Basket is empty', style: TextStyle(color: BlueIce.textMuted))) : ListView.separated(itemCount: _basket.length, separatorBuilder: (_, __) => const Divider(height: 1), itemBuilder: (_, i) {
          final line = _basket[i];
          return ListTile(title: Text(line.product.name), subtitle: Text('\$${line.product.price.toStringAsFixed(2)} each'), trailing: SizedBox(width: 120, child: Row(mainAxisAlignment: MainAxisAlignment.end, children: [
            IconButton(onPressed: () => setState(() { line.quantity -= 1; if (line.quantity <= 0) _basket.removeAt(i); }), icon: const Icon(Icons.remove_circle_outline)),
            Text(line.quantity.toStringAsFixed(0)),
            IconButton(onPressed: line.quantity < line.product.stock ? () => setState(() => line.quantity += 1) : null, icon: const Icon(Icons.add_circle_outline)),
          ])));
        })),
        const Divider(height: 1),
        Padding(padding: const EdgeInsets.all(16), child: Column(children: [
          Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [Text('Total', style: Theme.of(context).textTheme.titleMedium), Text('\$${_total.toStringAsFixed(2)}', style: Theme.of(context).textTheme.headlineSmall?.copyWith(color: BlueIce.accent))]),
          const SizedBox(height: 16),
          Row(children: [Expanded(child: FilledButton.icon(onPressed: _checkingOut || _basket.isEmpty ? null : () => _checkout('cash'), icon: const Icon(Icons.payments_outlined), label: const Text('Cash'))), const SizedBox(width: 8), Expanded(child: FilledButton.icon(onPressed: _checkingOut || _basket.isEmpty ? null : () => _checkout('card'), icon: const Icon(Icons.credit_card), label: const Text('Card')))]),
        ])),
      ]))),
    ]);
  }
}
