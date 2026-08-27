part of merdpos_staff;

enum CheckoutTenderMode { cash, card, split }

class CheckoutTenderDialog extends StatefulWidget {
  const CheckoutTenderDialog({
    super.key,
    required this.totalCents,
    required this.initialMode,
    this.initialPlan,
  });
  final int totalCents;
  final CheckoutTenderMode initialMode;
  final TenderPlan? initialPlan;

  @override
  State<CheckoutTenderDialog> createState() => _CheckoutTenderDialogState();
}

class _CheckoutTenderDialogState extends State<CheckoutTenderDialog> {
  late final TenderPlan _plan =
      widget.initialPlan?.copy() ?? TenderPlan(totalCents: widget.totalCents);
  final TextEditingController _amount = TextEditingController();
  String? _error;
  bool _finalCash = false;

  @override
  void initState() {
    super.initState();
    if (widget.initialPlan == null &&
        widget.initialMode == CheckoutTenderMode.card) {
      _plan.addCardRemaining();
    }
    if (widget.initialMode == CheckoutTenderMode.cash) _finalCash = true;
  }

  @override
  void dispose() {
    _amount.dispose();
    super.dispose();
  }

  void _addCash() {
    try {
      final int cents = moneyToCents(_amount.text);
      setState(() {
        _plan.addCash(cents, finalComponent: _finalCash);
        _amount.clear();
        _error = null;
      });
    } catch (error) {
      setState(() => _error = cleanError(error));
    }
  }

  void _addCardRemaining() {
    try {
      setState(() {
        _plan.addCardRemaining();
        _error = null;
      });
    } catch (error) {
      setState(() => _error = cleanError(error));
    }
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: Text(
      widget.initialMode == CheckoutTenderMode.split
          ? 'Split payment'
          : widget.initialMode == CheckoutTenderMode.cash
          ? 'Cash payment'
          : 'Card recorded',
    ),
    content: SizedBox(
      width: 520,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          _amountSummary(),
          const SizedBox(height: 16),
          if (widget.initialMode != CheckoutTenderMode.card) ...<Widget>[
            Row(
              children: <Widget>[
                Expanded(
                  child: TextField(
                    key: const Key('tender-amount'),
                    controller: _amount,
                    autofocus: true,
                    keyboardType: const TextInputType.numberWithOptions(
                      decimal: true,
                    ),
                    decoration: const InputDecoration(
                      labelText: 'Cash amount',
                      prefixText: '\$',
                    ),
                  ),
                ),
                const SizedBox(width: 10),
                FilledButton.icon(
                  onPressed: _addCash,
                  icon: const Icon(Icons.add),
                  label: const Text('Add cash'),
                ),
              ],
            ),
            if (widget.initialMode == CheckoutTenderMode.split)
              CheckboxListTile(
                value: _finalCash,
                onChanged: (value) =>
                    setState(() => _finalCash = value ?? false),
                contentPadding: EdgeInsets.zero,
                title: const Text('Final cash component'),
                subtitle: const Text(
                  'Allows deterministic change when cash exceeds the remaining balance.',
                ),
              ),
          ],
          if (widget.initialMode == CheckoutTenderMode.split &&
              _plan.remainingCents > 0)
            OutlinedButton.icon(
              key: const Key('card-remaining'),
              onPressed: _addCardRemaining,
              icon: const Icon(Icons.credit_card),
              label: Text(
                'Pay remaining \$${centsToMoney(_plan.remainingCents)} by card',
              ),
            ),
          if (_plan.components.isNotEmpty) ...<Widget>[
            const SizedBox(height: 12),
            ...List<Widget>.generate(_plan.components.length, (index) {
              final TenderComponent component = _plan.components[index];
              return ListTile(
                dense: true,
                leading: Icon(
                  component.type == TenderType.cash
                      ? Icons.payments_outlined
                      : Icons.credit_card,
                ),
                title: Text(
                  component.type == TenderType.cash ? 'Cash' : 'Card recorded',
                ),
                subtitle: component.changeCents > 0
                    ? Text('Change \$${centsToMoney(component.changeCents)}')
                    : null,
                trailing: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    Text(
                      '\$${centsToMoney(component.amountCents)}',
                      style: const TextStyle(fontWeight: FontWeight.w800),
                    ),
                    IconButton(
                      key: Key('remove-tender-$index'),
                      onPressed: () => setState(() => _plan.removeAt(index)),
                      icon: const Icon(Icons.close),
                    ),
                  ],
                ),
              );
            }),
          ],
          if (_error != null)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Text(
                _error!,
                style: const TextStyle(
                  color: _PosColors.error,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
        ],
      ),
    ),
    actions: <Widget>[
      TextButton(
        onPressed: () => Navigator.pop(context),
        child: const Text('Cancel'),
      ),
      FilledButton(
        key: const Key('complete-checkout'),
        onPressed: _plan.complete ? () => Navigator.pop(context, _plan) : null,
        child: const Text('Complete sale'),
      ),
    ],
  );

  Widget _amountSummary() => Container(
    padding: const EdgeInsets.all(16),
    decoration: BoxDecoration(
      color: _PosColors.brandSoft,
      borderRadius: BorderRadius.circular(12),
    ),
    child: Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: <Widget>[
        _SummaryValue(label: 'TOTAL', value: widget.totalCents),
        _SummaryValue(label: 'REMAINING', value: _plan.remainingCents),
        _SummaryValue(label: 'CHANGE', value: _plan.changeCents),
      ],
    ),
  );
}

class _SummaryValue extends StatelessWidget {
  const _SummaryValue({required this.label, required this.value});
  final String label;
  final int value;
  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: <Widget>[
      Text(
        label,
        style: const TextStyle(
          color: _PosColors.muted,
          fontSize: 10,
          fontWeight: FontWeight.w800,
        ),
      ),
      Text(
        '\$${centsToMoney(value)}',
        style: const TextStyle(
          color: _PosColors.ink,
          fontSize: 22,
          fontWeight: FontWeight.w900,
        ),
      ),
    ],
  );
}
