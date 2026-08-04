part of merdpos_staff;

class FinancialsPage extends StatefulWidget { const FinancialsPage({super.key}); @override State<FinancialsPage> createState()=>_FinancialsPageState(); }
class _FinancialsPageState extends State<FinancialsPage>{ Map<String,double>? _data; @override void initState(){super.initState();unawaited(_load());} Future<void> _load()async{final d=await RetailDb.financialSummary();if(mounted)setState(()=>_data=d);} @override Widget build(BuildContext context){final d=_data;return Padding(padding:const EdgeInsets.all(24),child:Column(crossAxisAlignment:CrossAxisAlignment.start,children:[
  const _ScreenHeader(storeName:'Retail',title:'Financials',subtitle:"Today's offline sales summary"),const SizedBox(height:18),
  if(d==null)const LinearProgressIndicator(minHeight:2) else Wrap(spacing:12,runSpacing:12,children:[
    _MetricCard(icon:Icons.payments_outlined,label:'Revenue',value:'\$${d['revenue']!.toStringAsFixed(2)}'),
    _MetricCard(icon:Icons.receipt_long,label:'Transactions',value:d['transactions']!.toStringAsFixed(0)),
    _MetricCard(icon:Icons.trending_up,label:'Gross margin',value:'\$${d['margin']!.toStringAsFixed(2)}'),
    _MetricCard(icon:Icons.shopping_basket_outlined,label:'Average sale',value:'\$${d['transactions']==0?'0.00':(d['revenue']!/d['transactions']!).toStringAsFixed(2)}'),
  ])]));}}
