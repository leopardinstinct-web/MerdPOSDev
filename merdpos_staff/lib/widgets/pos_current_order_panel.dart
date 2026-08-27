part of merdpos_staff;

class PosCurrentOrderPanel extends StatelessWidget {
  const PosCurrentOrderPanel({
    super.key,
    required this.basket,
    required this.checkingOut,
    required this.onEditQuantity,
    required this.onRemoveLine,
    required this.onIncrement,
    required this.onClear,
    required this.onCash,
    required this.onCard,
    required this.onSplit,
    required this.quantityText,
  });

  final PosBasket basket;
  final bool checkingOut;
  final ValueChanged<int> onEditQuantity;
  final ValueChanged<int> onRemoveLine;
  final ValueChanged<int> onIncrement;
  final VoidCallback onClear;
  final VoidCallback onCash;
  final VoidCallback onCard;
  final VoidCallback onSplit;
  final String Function(BasketLine line) quantityText;

  @override
  Widget build(BuildContext context) => ColoredBox(
    color: Colors.white,
    child: SafeArea(
      child: Column(
        children: <Widget>[
          _orderHeader(),
          const Divider(height: 1, color: _PosColors.border),
          Expanded(
            child: basket.lines.isEmpty
                ? const Center(
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: <Widget>[
                        Icon(
                          Icons.qr_code_scanner,
                          size: 42,
                          color: _PosColors.muted,
                        ),
                        SizedBox(height: 10),
                        Text(
                          'Scan an item to begin',
                          style: TextStyle(
                            color: _PosColors.ink,
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                        Text(
                          'The current order stays ready',
                          style: TextStyle(
                            color: _PosColors.muted,
                            fontSize: 12,
                          ),
                        ),
                      ],
                    ),
                  )
                : ListView.separated(
                    key: const Key('basket-lines'),
                    itemCount: basket.lines.length,
                    separatorBuilder: (_, __) =>
                        const Divider(height: 1, color: _PosColors.border),
                    itemBuilder: (context, index) => _line(index),
                  ),
          ),
          const Divider(height: 1, color: _PosColors.border),
          _fixedActions(),
        ],
      ),
    ),
  );

  Widget _orderHeader() => Padding(
    padding: const EdgeInsets.fromLTRB(18, 16, 14, 13),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Row(
          children: <Widget>[
            const Expanded(
              child: Text(
                'CURRENT ORDER',
                style: TextStyle(
                  color: _PosColors.ink,
                  fontSize: 18,
                  fontWeight: FontWeight.w900,
                ),
              ),
            ),
            Text(
              '${basket.lines.length} lines',
              style: const TextStyle(color: _PosColors.muted, fontSize: 12),
            ),
          ],
        ),
        const SizedBox(height: 11),
        const Row(
          children: <Widget>[
            _OrderMetaChip(icon: Icons.flash_on_outlined, label: 'Instant'),
            SizedBox(width: 7),
            _OrderMetaChip(
              icon: Icons.notes_outlined,
              label: 'Notes',
              disabled: true,
            ),
            SizedBox(width: 7),
            _OrderMetaChip(
              icon: Icons.person_outline,
              label: 'Customer',
              disabled: true,
            ),
          ],
        ),
      ],
    ),
  );

  Widget _line(int index) {
    final BasketLine line = basket.lines[index];
    final double projected = basket.projectedStock(line);
    final bool insufficient = basket.insufficientStock(line);
    final bool low = basket.lowStock(line);
    return Padding(
      padding: const EdgeInsets.fromLTRB(14, 12, 8, 12),
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
                    const SizedBox(height: 2),
                    Text(
                      '${line.barcodeUsed.isEmpty ? 'No barcode' : line.barcodeUsed} • ${line.product.unitOfMeasure} • \$${line.product.priceExact ?? line.product.price.toStringAsFixed(2)}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        color: _PosColors.muted,
                        fontSize: 11,
                      ),
                    ),
                  ],
                ),
              ),
              IconButton(
                key: Key('remove-line-$index'),
                tooltip: 'Remove line',
                onPressed: () => onRemoveLine(index),
                icon: const Icon(
                  Icons.delete_outline,
                  color: _PosColors.error,
                  size: 21,
                ),
              ),
            ],
          ),
          Row(
            children: <Widget>[
              OutlinedButton.icon(
                onPressed: () => onEditQuantity(index),
                icon: const Icon(Icons.edit, size: 15),
                label: Text(quantityText(line)),
              ),
              IconButton(
                onPressed: () => onIncrement(index),
                icon: const Icon(Icons.add_circle_outline),
                tooltip: 'Add one',
              ),
              const Spacer(),
              Text(
                '\$${basket.lineTotalExact(line)}',
                style: const TextStyle(
                  color: _PosColors.ink,
                  fontSize: 17,
                  fontWeight: FontWeight.w900,
                ),
              ),
            ],
          ),
          Text(
            'Projected ${projected.toStringAsFixed(line.product.unitOfMeasure == 'each' ? 0 : 3)}${insufficient
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
              fontSize: 11,
              fontWeight: insufficient || low
                  ? FontWeight.w800
                  : FontWeight.w500,
            ),
          ),
        ],
      ),
    );
  }

  Widget _fixedActions() => Padding(
    padding: const EdgeInsets.fromLTRB(18, 14, 18, 18),
    child: Column(
      children: <Widget>[
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: <Widget>[
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                const Text(
                  'TOTAL',
                  style: TextStyle(
                    color: _PosColors.muted,
                    fontSize: 11,
                    fontWeight: FontWeight.w800,
                    letterSpacing: 1.1,
                  ),
                ),
                Text(
                  '${basket.lines.length} line${basket.lines.length == 1 ? '' : 's'}',
                  style: const TextStyle(color: _PosColors.muted, fontSize: 11),
                ),
              ],
            ),
            Text(
              '\$${basket.totalExact}',
              key: const Key('basket-total'),
              style: const TextStyle(
                color: _PosColors.ink,
                fontSize: 30,
                fontWeight: FontWeight.w900,
              ),
            ),
          ],
        ),
        const SizedBox(height: 12),
        Row(
          children: <Widget>[
            OutlinedButton.icon(
              key: const Key('clear-basket'),
              onPressed: basket.lines.isEmpty ? null : onClear,
              icon: const Icon(Icons.delete_sweep_outlined),
              label: const Text('Clear'),
              style: OutlinedButton.styleFrom(
                foregroundColor: _PosColors.error,
              ),
            ),
            const SizedBox(width: 9),
            Expanded(
              child: FilledButton.icon(
                onPressed: checkingOut || basket.lines.isEmpty ? null : onCash,
                icon: const Icon(Icons.payments_outlined),
                label: const Text('Cash'),
              ),
            ),
            const SizedBox(width: 7),
            Expanded(
              child: FilledButton.icon(
                onPressed: checkingOut || basket.lines.isEmpty ? null : onCard,
                icon: const Icon(Icons.credit_card),
                label: const Text('Card'),
              ),
            ),
            const SizedBox(width: 7),
            Expanded(
              child: FilledButton.icon(
                key: const Key('split-checkout'),
                onPressed: checkingOut || basket.lines.isEmpty ? null : onSplit,
                icon: const Icon(Icons.call_split),
                label: const Text('Split'),
              ),
            ),
          ],
        ),
      ],
    ),
  );
}

class _OrderMetaChip extends StatelessWidget {
  const _OrderMetaChip({
    required this.icon,
    required this.label,
    this.disabled = false,
  });
  final IconData icon;
  final String label;
  final bool disabled;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
    decoration: BoxDecoration(
      color: disabled ? _PosColors.workspace : _PosColors.brandSoft,
      borderRadius: BorderRadius.circular(8),
    ),
    child: Row(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Icon(
          icon,
          size: 14,
          color: disabled ? _PosColors.muted : _PosColors.brand,
        ),
        const SizedBox(width: 4),
        Text(
          label,
          style: TextStyle(
            color: disabled ? _PosColors.muted : _PosColors.brand,
            fontSize: 10,
            fontWeight: FontWeight.w700,
          ),
        ),
      ],
    ),
  );
}
