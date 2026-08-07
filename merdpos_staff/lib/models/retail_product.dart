part of merdpos_staff;

class RetailProduct {
  const RetailProduct({
    required this.id,
    required this.barcode,
    required this.name,
    required this.category,
    required this.price,
    required this.cost,
    required this.stock,
    required this.active,
    this.unitOfMeasure = 'each',
    this.priceExact,
    this.taxCode,
    this.taxRateBasisPoints,
    this.lifecycle = 'active',
    this.storeAvailable = true,
    this.sellable = true,
    this.priceType,
    this.priceVersionId,
    this.promotionName,
    this.campaignReference,
    this.configurationIssues = const <String>[],
  });

  final int id;
  final String barcode;
  final String name;
  final String category;
  final double price;
  final double cost;
  final double stock;
  final bool active;
  final String unitOfMeasure;
  final String? priceExact;
  final String? taxCode;
  final int? taxRateBasisPoints;
  final String lifecycle;
  final bool storeAvailable;
  final bool sellable;
  final String? priceType;
  final int? priceVersionId;
  final String? promotionName;
  final String? campaignReference;
  final List<String> configurationIssues;

  double get margin => price - cost;

  factory RetailProduct.fromMap(Map<String, Object?> map) => RetailProduct(
    id: map['id'] as int,
    barcode:
        map['barcode']?.toString() ?? map['primary_barcode']?.toString() ?? '',
    name: map['name']?.toString() ?? '',
    category: map['category']?.toString() ?? 'General',
    price: (map['price'] as num?)?.toDouble() ?? 0,
    cost: (map['cost'] as num?)?.toDouble() ?? 0,
    stock: (map['stock'] as num?)?.toDouble() ?? 0,
    active: (map['active'] as int? ?? 1) == 1,
    unitOfMeasure: map['unit_of_measure']?.toString() ?? 'each',
    priceExact: map['resolved_price']?.toString(),
    taxCode: map['tax_code']?.toString(),
    taxRateBasisPoints: map['tax_rate_basis_points'] as int?,
    lifecycle: map['lifecycle']?.toString() ?? 'active',
    storeAvailable: (map['store_available'] as int? ?? 1) == 1,
    sellable: (map['sellable'] as int? ?? 1) == 1,
    priceType: map['price_type']?.toString(),
    priceVersionId: map['price_version_id'] as int?,
    promotionName: map['promotion_name']?.toString(),
    campaignReference: map['campaign_reference']?.toString(),
    configurationIssues: _decodeIssues(map['not_sellable_reasons_json']),
  );

  static List<String> _decodeIssues(Object? value) {
    if (value is! String || value.isEmpty) return const <String>[];
    final Object? decoded = jsonDecode(value);
    return decoded is List
        ? decoded.map((item) => item.toString()).toList(growable: false)
        : const <String>[];
  }
}

class BasketLine {
  BasketLine({required this.product, this.quantity = 1, String? barcodeUsed})
    : barcodeUsed = barcodeUsed ?? product.barcode;
  final RetailProduct product;
  final String barcodeUsed;
  double quantity;
  double get total => product.price * quantity;
}
