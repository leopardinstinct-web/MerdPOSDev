import 'package:flutter_test/flutter_test.dart';
import 'package:merdpos_staff/main.dart';

void main() {
  group('existing Timesheet parser regression fixtures', () {
    test('parses current detailed rows and preserves cross-midnight dates', () {
      final List<TimesheetRow> rows = TimesheetParser.parse(<String, dynamic>{
        'success': true,
        'detailed_rows': <Map<String, dynamic>>[
          <String, dynamic>{
            'USER_NAME': 'Fixture Employee',
            'STORE_NAME': 'Fixture Store',
            'IN_DATE': '2026-08-04',
            'ACTUAL_IN_TIME': '22:07:00',
            'ROUNDED_IN_TIME': '22:00:00',
            'OUT_DATE': '2026-08-05',
            'ACTUAL_OUT_TIME': '06:02:00',
            'ROUNDED_OUT_TIME': '06:00:00',
            'TOTAL_HOURS': '8.00',
            'WAGE': '240.00',
          },
        ],
      });

      expect(rows, hasLength(1));
      expect(rows.single.employee, 'Fixture Employee');
      expect(rows.single.store, 'Fixture Store');
      expect(rows.single.inDate, '2026-08-04');
      expect(rows.single.outDate, '2026-08-05');
      expect(rows.single.actualIn, '10:07 PM');
      expect(rows.single.actualOut, '6:02 AM');
      expect(rows.single.roundedIn, '10:00 PM');
      expect(rows.single.roundedOut, '6:00 AM');
      expect(rows.single.totalHoursValue, 8);
      expect(rows.single.wageValue, 240);
    });

    test('inherits employee names and removes duplicate detailed rows', () {
      const Map<String, dynamic> row = <String, dynamic>{
        'STORE_NAME': 'Fixture Store',
        'IN_DATE': '2026-08-04',
        'ACTUAL_IN_TIME': '09:00:00',
        'ACTUAL_OUT_TIME': '17:00:00',
        'TOTAL_HOURS': '8',
        'WAGE': '200',
      };
      final List<TimesheetRow> rows = TimesheetParser.parse(<String, dynamic>{
        'employee_wise_detailed_report': <Map<String, dynamic>>[
          <String, dynamic>{
            'employee_name': 'Inherited Fixture',
            'rows': <Map<String, dynamic>>[row, row],
          },
        ],
      });

      expect(rows, hasLength(1));
      expect(rows.single.employee, 'Inherited Fixture');
      expect(rows.single.totalHoursValue, 8);
    });

    test('ignores payloads without Timesheet row fields', () {
      final List<TimesheetRow> rows = TimesheetParser.parse(<String, dynamic>{
        'success': true,
        'data': <Map<String, dynamic>>[
          <String, dynamic>{'message': 'No shifts'},
        ],
      });

      expect(rows, isEmpty);
    });
  });
}
