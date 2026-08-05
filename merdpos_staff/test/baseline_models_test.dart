import 'package:flutter_test/flutter_test.dart';
import 'package:merdpos_staff/main.dart';

void main() {
  group('baseline model fixtures', () {
    test('employee parsing preserves numeric user identifiers', () {
      final Employee employee = Employee.fromMap(<String, dynamic>{
        'id': '42',
        'full_name': 'Ada Lovelace',
        'user_id': 1007,
        'role_name': 'ADMIN',
        'hourly_rate': '31.50',
      });

      expect(employee.id, 42);
      expect(employee.fullName, 'Ada Lovelace');
      expect(employee.userId, '1007');
      expect(employee.roleName, 'ADMIN');
      expect(employee.hourlyRate, '31.50');
      expect(employee.shortName, 'Ada');
      expect(employee.initial, 'A');
    });

    test('retail product and basket calculations use local fixture values', () {
      final RetailProduct product = RetailProduct.fromMap(<String, Object?>{
        'id': 7,
        'barcode': '930000000007',
        'name': 'Fixture Product',
        'category': 'Fixture',
        'price': 9.5,
        'cost': 4.25,
        'stock': 12,
        'active': 1,
      });
      final BasketLine line = BasketLine(product: product, quantity: 3);

      expect(product.margin, 5.25);
      expect(product.stock, 12);
      expect(product.active, isTrue);
      expect(line.total, 28.5);
    });

    test('retail sale defaults unsupplied sync state to pending', () {
      final RetailSale sale = RetailSale.fromMap(<String, Object?>{
        'id': 9,
        'sale_number': 'S-FIXTURE-9',
        'created_at': '2026-08-05T10:00:00',
        'cashier_name': 'Fixture Cashier',
        'total': 18,
        'payment_method': 'cash',
        'status': 'completed',
      });

      expect(sale.saleNumber, 'S-FIXTURE-9');
      expect(sale.total, 18);
      expect(sale.paymentMethod, 'cash');
      expect(sale.syncStatus, 'pending');
    });
  });
}
