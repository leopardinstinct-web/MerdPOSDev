part of merdpos_staff;

class HomePage extends StatefulWidget {
  const HomePage({super.key, required this.session, required this.primaryEmployee, required this.employees});
  final AppSession session; final Employee primaryEmployee; final List<Employee> employees;
  @override State<HomePage> createState()=>_HomePageState();
}

class _HomePageState extends State<HomePage> {
  late Employee _primaryEmployee;
  late List<Employee> _employees;
  Employee? _secondaryEmployee;
  AppModule _module = AppModule.home;
  bool _syncing=false;
  String? _message; String? _error;

  @override void initState(){super.initState();_primaryEmployee=widget.primaryEmployee;_employees=_mergeEmployee(widget.employees,widget.primaryEmployee);}
  void _showInfo(String m)=>ScaffoldMessenger.of(context).showSnackBar(SnackBar(content:Text(m)));
  Future<void> _showChangePasswordDialog(Employee employee)=>showDialog<void>(context:context,builder:(_)=>ChangePasswordDialog(session:widget.session,employee:employee));
  void _openTimesheet(Employee employee){Navigator.of(context).push(MaterialPageRoute(builder:(_)=>TimesheetPage(session:widget.session,employee:employee,primary:_primaryEmployee,secondary:_secondaryEmployee,onPrimaryChangePassword:()=>_showChangePasswordDialog(_primaryEmployee),onPrimaryLogOff:_logoutPrimary,onAddUser:()async{final added=await _addSecondaryUser();if(added&&mounted&&Navigator.of(context).canPop())Navigator.of(context).pop();},onSecondaryLogOff:_logOffSecondary)));}
  Future<void> _logoutPrimary()async{final secondary=_secondaryEmployee;if(secondary!=null){await PrimaryLoginStore.save(secondary);if(!mounted)return;setState((){_primaryEmployee=secondary;_secondaryEmployee=null;_message='${secondary.fullName} is now the active user.';_error=null;});return;}await PrimaryLoginStore.clear();if(!mounted)return;Navigator.of(context).pushReplacement(MaterialPageRoute(builder:(_)=>LoginPage(session:widget.session,onResetSetup:()async{})));}
  Future<bool> _addSecondaryUser()async{if(_secondaryEmployee!=null){_showInfo('Maximum 2 users allowed at the same time.');return false;}final employee=await showDialog<Employee>(context:context,builder:(_)=>SecondaryLoginDialog(session:widget.session,primaryEmployee:_primaryEmployee));if(employee==null)return false;setState((){_secondaryEmployee=employee;_employees=_mergeEmployee(_employees,employee);});return true;}
  void _logOffSecondary()=>setState(()=>_secondaryEmployee=null);
  Future<void> _syncRetail()async{setState((){_syncing=true;_message=null;_error=null;});try{final count=await RetailDb.sync(widget.session);if(mounted)setState(()=>_message=count==0?'Everything is already synced.':'Synced $count retail records.');}catch(e){if(mounted)setState(()=>_error=cleanError(e));}finally{if(mounted)setState(()=>_syncing=false);}}

  Widget _home(){return Padding(padding:const EdgeInsets.all(24),child:Column(crossAxisAlignment:CrossAxisAlignment.start,children:[
    _ScreenHeader(storeName:widget.session.storeName,title:'Home',subtitle:'MerdPOS $kAppVersion • API Timesheet v2'),const SizedBox(height:18),
    Wrap(spacing:12,runSpacing:12,children:[_MetricCard(icon:Icons.person,label:'Primary',value:_primaryEmployee.fullName),_MetricCard(icon:Icons.people,label:'Visible users',value:_secondaryEmployee==null?'1 user':'2 users'),const _MetricCard(icon:Icons.offline_bolt_outlined,label:'Retail mode',value:'Offline ready')]),
    if(_syncing)...[const SizedBox(height:14),const LinearProgressIndicator(minHeight:2)],
    if(_message!=null)...[const SizedBox(height:12),_MessageCard(message:_message!,type:MessageType.info)],
    if(_error!=null)...[const SizedBox(height:12),_MessageCard(message:_error!,type:MessageType.error)],
    const SizedBox(height:24),Text('Retail modules',style:Theme.of(context).textTheme.titleMedium),const SizedBox(height:12),
    Wrap(spacing:12,runSpacing:12,children:[
      _HomeAction(icon:Icons.point_of_sale,title:'Start sale',subtitle:'Search, scan and checkout',onTap:()=>setState(()=>_module=AppModule.pos)),
      _HomeAction(icon:Icons.receipt_long_outlined,title:'Orders',subtitle:'Review completed sales',onTap:()=>setState(()=>_module=AppModule.orders)),
      _HomeAction(icon:Icons.inventory_2_outlined,title:'Inventory',subtitle:'Stock levels and adjustments',onTap:()=>setState(()=>_module=AppModule.inventory)),
      _HomeAction(icon:Icons.account_balance_wallet_outlined,title:'Financials',subtitle:"Today's revenue and margin",onTap:()=>setState(()=>_module=AppModule.financials)),
    ])
  ]));}

  Widget _content(){switch(_module){case AppModule.pos:return PosPage(session:widget.session,cashier:_primaryEmployee);case AppModule.orders:return const OrdersPage();case AppModule.inventory:return const InventoryPage();case AppModule.financials:return const FinancialsPage();case AppModule.home:return _home();}}
  @override Widget build(BuildContext context)=>Scaffold(body:Row(children:[_PosSideRail(primary:_primaryEmployee,secondary:_secondaryEmployee,selected:_module,onPrimaryTimesheet:()=>_openTimesheet(_primaryEmployee),onPrimaryChangePassword:()=>_showChangePasswordDialog(_primaryEmployee),onPrimaryLogOff:_logoutPrimary,onAddUser:()async=>_addSecondaryUser(),onSecondaryLogOff:_logOffSecondary,onHome:()=>setState(()=>_module=AppModule.home),onPos:()=>setState(()=>_module=AppModule.pos),onOrders:()=>setState(()=>_module=AppModule.orders),onFinancials:()=>setState(()=>_module=AppModule.financials),onInventory:()=>setState(()=>_module=AppModule.inventory),onSync:_syncing?null:_syncRetail,onSettings:()=>_showInfo('Settings will be expanded in the next module.')),Expanded(child:SafeArea(child:_content()))]));
}

class _HomeAction extends StatelessWidget{const _HomeAction({required this.icon,required this.title,required this.subtitle,required this.onTap});final IconData icon;final String title;final String subtitle;final VoidCallback onTap;@override Widget build(BuildContext context)=>SizedBox(width:250,height:130,child:InkWell(onTap:onTap,borderRadius:BorderRadius.circular(10),child:Card(child:Padding(padding:const EdgeInsets.all(16),child:Column(crossAxisAlignment:CrossAxisAlignment.start,children:[Icon(icon,color:BlueIce.accent),const Spacer(),Text(title,style:Theme.of(context).textTheme.titleMedium),const SizedBox(height:4),Text(subtitle,style:Theme.of(context).textTheme.bodySmall)])))));}
