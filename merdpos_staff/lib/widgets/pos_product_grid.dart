part of merdpos_staff;

class PosProductGrid extends StatelessWidget {
  const PosProductGrid({
    super.key,
    required this.loading,
    required this.products,
    required this.searchController,
    required this.selectedCategory,
    required this.scannerStatus,
    required this.scannerError,
    required this.catalogueStatus,
    required this.catalogueWarning,
    required this.storeContext,
    required this.onSearchChanged,
    required this.onSearchSubmitted,
    required this.onClearSearch,
    required this.onManualBarcode,
    required this.onProductTap,
  });

  final bool loading;
  final List<RetailProduct> products;
  final TextEditingController searchController;
  final String? selectedCategory;
  final String scannerStatus;
  final bool scannerError;
  final String catalogueStatus;
  final bool catalogueWarning;
  final String storeContext;
  final ValueChanged<String> onSearchChanged;
  final ValueChanged<String> onSearchSubmitted;
  final VoidCallback onClearSearch;
  final VoidCallback onManualBarcode;
  final ValueChanged<RetailProduct> onProductTap;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.all(18),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Row(
          children: <Widget>[
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  Text(
                    selectedCategory ?? 'All products',
                    key: const Key('products-heading'),
                    style: const TextStyle(
                      color: _PosColors.ink,
                      fontSize: 22,
                      fontWeight: FontWeight.w900,
                    ),
                  ),
                  const SizedBox(height: 3),
                  Text(
                    storeContext,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      color: _PosColors.muted,
                      fontSize: 12,
                    ),
                  ),
                ],
              ),
            ),
            _PosStatusPill(label: catalogueStatus, warning: catalogueWarning),
          ],
        ),
        const SizedBox(height: 12),
        Row(
          children: <Widget>[
            Expanded(
              child: TextField(
                key: const Key('product-search'),
                controller: searchController,
                onChanged: onSearchChanged,
                onSubmitted: onSearchSubmitted,
                style: const TextStyle(color: _PosColors.ink),
                decoration: InputDecoration(
                  prefixIcon: const Icon(Icons.search),
                  hintText: 'Search name, SKU or barcode',
                  fillColor: Colors.white,
                  suffixIcon: searchController.text.isEmpty
                      ? null
                      : IconButton(
                          onPressed: onClearSearch,
                          icon: const Icon(Icons.close),
                          tooltip: 'Clear search',
                        ),
                ),
              ),
            ),
            const SizedBox(width: 10),
            OutlinedButton.icon(
              onPressed: onManualBarcode,
              icon: const Icon(Icons.keyboard),
              label: const Text('Barcode'),
            ),
          ],
        ),
        const SizedBox(height: 10),
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          decoration: BoxDecoration(
            color: scannerError ? _PosColors.errorSoft : _PosColors.successSoft,
            borderRadius: BorderRadius.circular(9),
          ),
          child: Row(
            children: <Widget>[
              Icon(
                scannerError ? Icons.error_outline : Icons.qr_code_scanner,
                size: 19,
                color: scannerError ? _PosColors.error : _PosColors.success,
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  scannerStatus,
                  key: const Key('scanner-status'),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    color: scannerError ? _PosColors.error : _PosColors.ink,
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
              Text(
                '${products.length} items',
                style: const TextStyle(color: _PosColors.muted, fontSize: 12),
              ),
            ],
          ),
        ),
        const SizedBox(height: 12),
        Expanded(
          child: loading
              ? const Center(child: CircularProgressIndicator())
              : products.isEmpty
              ? const Center(
                  child: Text(
                    'No products match this view.',
                    style: TextStyle(color: _PosColors.muted),
                  ),
                )
              : GridView.builder(
                  key: const Key('product-grid'),
                  gridDelegate: const SliverGridDelegateWithMaxCrossAxisExtent(
                    maxCrossAxisExtent: 190,
                    mainAxisExtent: 142,
                    crossAxisSpacing: 10,
                    mainAxisSpacing: 10,
                  ),
                  itemCount: products.length,
                  itemBuilder: (context, index) => PosProductTile(
                    product: products[index],
                    onTap: () => onProductTap(products[index]),
                  ),
                ),
        ),
      ],
    ),
  );
}

class PosProductTile extends StatelessWidget {
  const PosProductTile({super.key, required this.product, required this.onTap});
  final RetailProduct product;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final String? issue = productConfigurationError(product);
    final bool lowStock = product.stock <= 5;
    return Material(
      color: Colors.white,
      borderRadius: BorderRadius.circular(11),
      child: InkWell(
        key: Key('product-${product.id}'),
        onTap: onTap,
        borderRadius: BorderRadius.circular(11),
        child: Container(
          padding: const EdgeInsets.all(11),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(11),
            border: Border.all(
              color: issue != null
                  ? _PosColors.error
                  : lowStock
                  ? _PosColors.warning
                  : _PosColors.border,
            ),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              Container(
                height: 24,
                width: 38,
                decoration: BoxDecoration(
                  color: _PosColors.workspace,
                  borderRadius: BorderRadius.circular(6),
                ),
                child: const Icon(
                  Icons.inventory_2_outlined,
                  size: 15,
                  color: _PosColors.muted,
                ),
              ),
              const SizedBox(height: 7),
              Text(
                product.name,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(
                  color: _PosColors.ink,
                  fontWeight: FontWeight.w800,
                  height: 1.05,
                ),
              ),
              const Spacer(),
              if (issue != null)
                Text(
                  issue,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    color: _PosColors.error,
                    fontSize: 10,
                    fontWeight: FontWeight.w800,
                  ),
                )
              else if (product.promotionName != null)
                Text(
                  product.promotionName!,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    color: _PosColors.brand,
                    fontSize: 10,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: <Widget>[
                  Expanded(
                    child: Text(
                      '\$${product.priceExact ?? product.price.toStringAsFixed(2)}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        color: _PosColors.brand,
                        fontSize: 16,
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                  ),
                  const SizedBox(width: 4),
                  Text(
                    '${product.stock.toStringAsFixed(product.unitOfMeasure == 'each' ? 0 : 3)} ${product.unitOfMeasure}',
                    style: TextStyle(
                      color: lowStock ? _PosColors.warning : _PosColors.muted,
                      fontSize: 9,
                      fontWeight: lowStock ? FontWeight.w800 : FontWeight.w500,
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _PosStatusPill extends StatelessWidget {
  const _PosStatusPill({required this.label, required this.warning});
  final String label;
  final bool warning;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
    decoration: BoxDecoration(
      color: warning ? _PosColors.warningSoft : _PosColors.successSoft,
      borderRadius: BorderRadius.circular(99),
    ),
    child: Text(
      label,
      style: TextStyle(
        color: warning ? _PosColors.warning : _PosColors.success,
        fontSize: 11,
        fontWeight: FontWeight.w800,
      ),
    ),
  );
}
