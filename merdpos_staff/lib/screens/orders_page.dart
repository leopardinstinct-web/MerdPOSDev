part of merdpos_staff;

class OrdersPage extends StatefulWidget {
  const OrdersPage({super.key});
  @override State<OrdersPage> createState() => _OrdersPageState();
}
class _OrdersPageState extends State<OrdersPage> {
  List<RetailSale> _sales = [];
  bool _loading = true;
  @override void initState(){super.initState(); unawaited(_load());}
  Future<void> _load() async { final sales = await RetailDb.sales(); if(mounted) setState((){_sales=sales;_loading=false;}); }
  @override Widget build(BuildContext context) => Padding(padding: const EdgeInsets.all(24), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children:[
    const _ScreenHeader(storeName: 'Retail', title: 'Orders', subtitle: 'Completed offline and synced sales'), const SizedBox(height:16),
    Expanded(child:_loading?const Center(child:CircularProgressIndicator()):Card(child: SingleChildScrollView(child: SizedBox(width:double.infinity, child:DataTable(columns:const[
      DataColumn(label:Text('Order')),DataColumn(label:Text('Created')),DataColumn(label:Text('Cashier')),DataColumn(label:Text('Payment')),DataColumn(label:Text('Total'),numeric:true),DataColumn(label:Text('Sync'))], rows:_sales.map((s)=>DataRow(cells:[DataCell(Text(s.saleNumber)),DataCell(Text(s.createdAt.replaceFirst('T',' ').split('.').first)),DataCell(Text(s.cashierName)),DataCell(Text(s.paymentMethod.toUpperCase())),DataCell(Text('\$${s.total.toStringAsFixed(2)}')),DataCell(Text(s.syncStatus,style:TextStyle(color:s.syncStatus=='synced'?BlueIce.success:BlueIce.warning)))] )).toList())))))
  ]));
}
