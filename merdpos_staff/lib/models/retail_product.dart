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
  });

  final int id;
  final String barcode;
  final String name;
  final String category;
  final double price;
  final double cost;
  final double stock;
  final bool active;

  double get margin => price - cost;

  factory RetailProduct.fromMap(Map<String, Object?> map) => RetailProduct(
        id: map['id'] as int,
        barcode: map['barcode']?.toString() ?? '',
        name: map['name']?.toString() ?? '',
        category: map['category']?.toString() ?? 'General',
        price: (map['price'] as num?)?.toDouble() ?? 0,
        cost: (map['cost'] as num?)?.toDouble() ?? 0,
        stock: (map['stock'] as num?)?.toDouble() ?? 0,
        active: (map['active'] as int? ?? 1) == 1,
      );
}

class BasketLine {
  BasketLine({required this.product, this.quantity = 1});
  final RetailProduct product;
  double quantity;
  double get total => product.price * quantity;
}
