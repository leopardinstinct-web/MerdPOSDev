part of merdpos_staff;

class RetailSale {
  const RetailSale({
    required this.id,
    required this.saleNumber,
    required this.createdAt,
    required this.cashierName,
    required this.total,
    required this.paymentMethod,
    required this.status,
    required this.syncStatus,
  });

  final int id;
  final String saleNumber;
  final String createdAt;
  final String cashierName;
  final double total;
  final String paymentMethod;
  final String status;
  final String syncStatus;

  factory RetailSale.fromMap(Map<String, Object?> map) => RetailSale(
        id: map['id'] as int,
        saleNumber: map['sale_number']?.toString() ?? '',
        createdAt: map['created_at']?.toString() ?? '',
        cashierName: map['cashier_name']?.toString() ?? '',
        total: (map['total'] as num?)?.toDouble() ?? 0,
        paymentMethod: map['payment_method']?.toString() ?? '',
        status: map['status']?.toString() ?? '',
        syncStatus: map['sync_status']?.toString() ?? 'pending',
      );
}
