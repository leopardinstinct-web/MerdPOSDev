part of merdpos_staff;

class ScannerInputBuffer {
  ScannerInputBuffer({
    this.terminator = '\n',
    this.duplicateDebounce = const Duration(milliseconds: 75),
  });

  final String terminator;
  final Duration duplicateDebounce;
  final StringBuffer _buffer = StringBuffer();
  String? _lastValue;
  DateTime? _lastAt;

  String? add(String character, DateTime now) {
    if (character != terminator) {
      if (character.length == 1) _buffer.write(character);
      return null;
    }
    final String value = _buffer.toString();
    _buffer.clear();
    if (value.isEmpty) return null;
    final bool duplicate =
        value == _lastValue &&
        _lastAt != null &&
        now.difference(_lastAt!) < duplicateDebounce;
    _lastValue = value;
    _lastAt = now;
    return duplicate ? null : value;
  }

  void clear() => _buffer.clear();
}

class BasketValidationException implements Exception {
  const BasketValidationException(this.message);
  final String message;
  @override
  String toString() => message;
}

class PosBasket {
  final List<BasketLine> _lines = <BasketLine>[];
  List<BasketLine> get lines => List<BasketLine>.unmodifiable(_lines);

  void add(RetailProduct product, {required String barcodeUsed}) {
    final String? error = productConfigurationError(product);
    if (error != null) throw BasketValidationException(error);
    final int index = _lines.indexWhere(
      (line) => line.product.id == product.id,
    );
    if (index < 0) {
      _lines.add(BasketLine(product: product, barcodeUsed: barcodeUsed));
    } else {
      setQuantity(index, _lines[index].quantity + 1);
    }
  }

  void setQuantity(int index, double value) {
    if (value <= 0)
      throw const BasketValidationException(
        'Quantity must be greater than zero.',
      );
    final String uom = _lines[index].product.unitOfMeasure;
    if (uom == 'each' && value != value.roundToDouble()) {
      throw const BasketValidationException(
        'Each products require a whole quantity.',
      );
    }
    if ((value * 1000).roundToDouble() != value * 1000) {
      throw const BasketValidationException(
        'Quantity supports up to three decimal places.',
      );
    }
    _lines[index].quantity = value;
  }

  void removeAt(int index) => _lines.removeAt(index);
  void clear() => _lines.clear();

  String get totalExact {
    int cents = 0;
    for (final BasketLine line in _lines) {
      cents += _lineCents(line);
    }
    return '${cents ~/ 100}.${(cents % 100).toString().padLeft(2, '0')}';
  }

  String lineTotalExact(BasketLine line) {
    final int cents = _lineCents(line);
    return '${cents ~/ 100}.${(cents % 100).toString().padLeft(2, '0')}';
  }

  double projectedStock(BasketLine line) => line.product.stock - line.quantity;
  bool insufficientStock(BasketLine line) => projectedStock(line) < 0;
  bool lowStock(BasketLine line) =>
      !insufficientStock(line) && projectedStock(line) <= 5;

  static int _lineCents(BasketLine line) {
    final String price =
        line.product.priceExact ?? line.product.price.toString();
    final List<String> parts = price.split('.');
    final String fraction = parts.length == 1 ? '' : parts[1];
    final int scale = fraction.length;
    final int priceUnits = int.parse('${parts[0]}$fraction');
    final int quantityUnits = (line.quantity * 1000).round();
    final int numerator = priceUnits * quantityUnits * 100;
    final int denominator = _pow10(scale) * 1000;
    return (numerator * 2 + denominator) ~/ (denominator * 2);
  }

  static int _pow10(int exponent) {
    int value = 1;
    for (int i = 0; i < exponent; i++) value *= 10;
    return value;
  }
}

String? productConfigurationError(RetailProduct product) {
  if (product.lifecycle == 'archived' || product.lifecycle == 'tombstoned') {
    return '${product.name} is archived.';
  }
  if (product.lifecycle == 'disabled' || !product.active)
    return '${product.name} is disabled.';
  if (!product.storeAvailable)
    return '${product.name} is unavailable at this store.';
  if (product.priceExact == null || product.priceExact!.isEmpty)
    return '${product.name} has no valid price.';
  if (product.taxCode == null ||
      product.taxCode!.isEmpty ||
      product.taxRateBasisPoints == null) {
    return '${product.name} has no valid tax configuration.';
  }
  if (!product.sellable) {
    return product.configurationIssues.isEmpty
        ? '${product.name} is not configured for sale.'
        : '${product.name}: ${product.configurationIssues.join(', ')}.';
  }
  return null;
}
