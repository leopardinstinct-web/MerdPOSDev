part of merdpos_staff;

enum TenderType { cash, cardRecorded }

class TenderComponent {
  const TenderComponent({
    required this.type,
    required this.amountCents,
    this.changeCents = 0,
    this.externalReference,
  });
  final TenderType type;
  final int amountCents;
  final int changeCents;
  final String? externalReference;
  String get typeValue => type == TenderType.cash ? 'cash' : 'card_recorded';
  int get effectiveCents => amountCents - changeCents;
}

class TenderPlan {
  TenderPlan({required this.totalCents});
  final int totalCents;
  final List<TenderComponent> _components = <TenderComponent>[];
  List<TenderComponent> get components =>
      List<TenderComponent>.unmodifiable(_components);
  int get paidCents =>
      _components.fold(0, (sum, item) => sum + item.effectiveCents);
  int get remainingCents => totalCents - paidCents;
  int get changeCents =>
      _components.fold(0, (sum, item) => sum + item.changeCents);
  bool get complete => remainingCents == 0 && _components.isNotEmpty;

  TenderPlan copy() {
    final TenderPlan result = TenderPlan(totalCents: totalCents);
    result._components.addAll(_components);
    return result;
  }

  void addCardRemaining() => addCard(remainingCents);

  void addCard(int cents) {
    if (cents <= 0)
      throw const BasketValidationException(
        'Card amount must be greater than zero.',
      );
    if (cents > remainingCents)
      throw const BasketValidationException(
        'Card amount cannot exceed the remaining balance.',
      );
    _components.add(
      TenderComponent(type: TenderType.cardRecorded, amountCents: cents),
    );
  }

  void addCash(int cents, {required bool finalComponent}) {
    if (cents <= 0)
      throw const BasketValidationException(
        'Cash amount must be greater than zero.',
      );
    if (!finalComponent && cents > remainingCents) {
      throw const BasketValidationException(
        'Non-final cash cannot exceed the remaining balance.',
      );
    }
    final int change = finalComponent && cents > remainingCents
        ? cents - remainingCents
        : 0;
    _components.add(
      TenderComponent(
        type: TenderType.cash,
        amountCents: cents,
        changeCents: change,
      ),
    );
  }

  void removeAt(int index) => _components.removeAt(index);
}

class CheckoutLineAmounts {
  const CheckoutLineAmounts({
    required this.grossCents,
    required this.taxCents,
    required this.netCents,
  });
  final int grossCents;
  final int taxCents;
  final int netCents;
}

class CheckoutAmounts {
  const CheckoutAmounts({
    required this.lines,
    required this.subtotalCents,
    required this.taxCents,
    required this.totalCents,
  });
  final List<CheckoutLineAmounts> lines;
  final int subtotalCents;
  final int taxCents;
  final int totalCents;

  factory CheckoutAmounts.fromBasket(PosBasket basket) {
    final List<CheckoutLineAmounts> lines = <CheckoutLineAmounts>[];
    int total = 0;
    int tax = 0;
    for (final BasketLine line in basket.lines) {
      final String? error = productConfigurationError(line.product);
      if (error != null) throw BasketValidationException(error);
      final int gross = moneyToCents(basket.lineTotalExact(line));
      final int rate = line.product.taxRateBasisPoints!;
      final int lineTax = rate == 0
          ? 0
          : _roundRatio(gross * rate, 10000 + rate);
      lines.add(
        CheckoutLineAmounts(
          grossCents: gross,
          taxCents: lineTax,
          netCents: gross - lineTax,
        ),
      );
      total += gross;
      tax += lineTax;
    }
    return CheckoutAmounts(
      lines: lines,
      subtotalCents: total,
      taxCents: tax,
      totalCents: total,
    );
  }
}

int moneyToCents(String value) {
  if (!RegExp(r'^\d+(?:\.\d{1,2})?$').hasMatch(value))
    throw const FormatException('Money must use at most two decimal places.');
  final List<String> parts = value.split('.');
  return int.parse(parts[0]) * 100 +
      int.parse((parts.length == 1 ? '' : parts[1]).padRight(2, '0'));
}

String centsToMoney(int cents) {
  final String sign = cents < 0 ? '-' : '';
  final int absolute = cents.abs();
  return '$sign${absolute ~/ 100}.${(absolute % 100).toString().padLeft(2, '0')}';
}

int _roundRatio(int numerator, int denominator) =>
    (numerator * 2 + denominator) ~/ (denominator * 2);

class CheckoutCommitResult {
  const CheckoutCommitResult({
    required this.saleId,
    required this.saleUid,
    required this.saleNumber,
    required this.totalCents,
    required this.tenders,
  });
  final int saleId;
  final String saleUid;
  final String saleNumber;
  final int totalCents;
  final List<TenderComponent> tenders;
}
