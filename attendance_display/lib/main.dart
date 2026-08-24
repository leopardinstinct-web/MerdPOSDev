import 'dart:async';
import 'dart:convert';
import 'dart:math';
import 'dart:typed_data';

import 'package:cryptography/cryptography.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;
import 'package:qr_flutter/qr_flutter.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  SystemChrome.setEnabledSystemUIMode(SystemUiMode.immersiveSticky);
  SystemChrome.setPreferredOrientations([DeviceOrientation.landscapeLeft, DeviceOrientation.landscapeRight]);
  runApp(const AttendanceDisplayApp());
}
class AttendanceDisplayApp extends StatelessWidget {
  const AttendanceDisplayApp({super.key});
  @override
  Widget build(BuildContext context) => MaterialApp(
    debugShowCheckedModeBanner: false,
    theme: ThemeData(colorScheme: ColorScheme.fromSeed(seedColor: const Color(0xff0b63b6)), useMaterial3: true),
    home: const AttendanceHome(),
  );
}

class AttendanceConfig {
  final int clientId, storeId;
  final String deviceUuid, storeName, backendUrl, portalUrl;
  const AttendanceConfig({required this.clientId, required this.storeId, required this.deviceUuid, required this.storeName, required this.backendUrl, required this.portalUrl});
}

class AttendanceHome extends StatefulWidget {
  const AttendanceHome({super.key});
  @override State<AttendanceHome> createState() => _AttendanceHomeState();
}

class _AttendanceHomeState extends State<AttendanceHome> {
  static const secure = FlutterSecureStorage(aOptions: AndroidOptions(encryptedSharedPreferences: true));
  final algorithm = Ed25519();
  AttendanceConfig? config;
  SimpleKeyPairData? keyPair;
  String qr = '';
  String? error;
  int window = -1, secondsLeft = 0;
  Timer? timer;

  @override void initState() { super.initState(); _load(); }
  @override void dispose() { timer?.cancel(); super.dispose(); }

  Future<void> _load() async {
    final prefs = await SharedPreferences.getInstance();
    final seed = await secure.read(key: 'attendance_private_seed');
    final public = await secure.read(key: 'attendance_public_key');
    final did = prefs.getString('device_uuid');
    if (seed != null && public != null && did != null) {
      config = AttendanceConfig(clientId:prefs.getInt('client_id')!,storeId:prefs.getInt('store_id')!,deviceUuid:did,
        storeName:prefs.getString('store_name')!,backendUrl:prefs.getString('backend_url')!,portalUrl:prefs.getString('portal_url')!);
      keyPair = SimpleKeyPairData(base64Decode(seed), publicKey: SimplePublicKey(base64Decode(public), type: KeyPairType.ed25519), type: KeyPairType.ed25519);
      timer = Timer.periodic(const Duration(seconds: 1), (_) => _tick()); await _tick();
    }
    if (mounted) setState(() {});
  }

  Future<void> _tick() async {
    if (config == null || keyPair == null) return;
    final epoch = DateTime.now().toUtc().millisecondsSinceEpoch ~/ 1000;
    final nextWindow = epoch ~/ 30;
    secondsLeft = 30 - (epoch % 30);
    if (nextWindow != window) {
      window = nextWindow;
      final issued = nextWindow * 30;
      final nonce = _b64(Uint8List.fromList(List<int>.generate(18, (_) => Random.secure().nextInt(256))));
      final claims = jsonEncode({'v':1,'did':config!.deviceUuid,'iat':issued,'exp':issued + 90,'n':nonce});
      final encoded = _b64(Uint8List.fromList(utf8.encode(claims)));
      final signature = await algorithm.sign(utf8.encode(encoded), keyPair:keyPair!);
      qr = '${config!.portalUrl}?q=$encoded.${_b64(Uint8List.fromList(signature.bytes))}';
    }
    if (mounted) setState(() {});
  }

  String _b64(Uint8List bytes) => base64UrlEncode(bytes).replaceAll('=', '');

  Future<void> _provision(Map<String,String> values) async {
    final generated = await algorithm.newKeyPair();
    final extracted = await generated.extract();
    final public = await extracted.extractPublicKey();
    final body = jsonEncode({'client_id':int.parse(values['client_id']!),'store_id':int.parse(values['store_id']!),
      'device_uuid':values['device_uuid'],'public_key_b64':base64Encode(public.bytes)});
    final response = await http.post(Uri.parse('${values['backend_url']}/register_attendance_key.php'),
      headers:{'Content-Type':'application/json','Authorization':'Bearer ${values['activation_token']}'},body:body).timeout(const Duration(seconds:20));
    final result = jsonDecode(response.body) as Map<String,dynamic>;
    if (response.statusCode >= 300 || result['success'] != true) throw Exception(result['error'] ?? 'Key registration failed');
    final prefs = await SharedPreferences.getInstance();
    await prefs.setInt('client_id',int.parse(values['client_id']!)); await prefs.setInt('store_id',int.parse(values['store_id']!));
    for (final key in ['device_uuid','store_name','backend_url','portal_url']) await prefs.setString(key,values[key]!);
    await secure.write(key:'attendance_private_seed',value:base64Encode(extracted.bytes));
    await secure.write(key:'attendance_public_key',value:base64Encode(public.bytes));
    config = AttendanceConfig(clientId:int.parse(values['client_id']!),storeId:int.parse(values['store_id']!),deviceUuid:values['device_uuid']!,storeName:values['store_name']!,backendUrl:values['backend_url']!,portalUrl:values['portal_url']!);
    keyPair = extracted; timer?.cancel(); timer=Timer.periodic(const Duration(seconds:1),(_)=>_tick()); await _tick();
  }

  Future<void> _reset() async { final prefs=await SharedPreferences.getInstance(); await prefs.clear(); await secure.deleteAll(); timer?.cancel(); setState(() {config=null;keyPair=null;qr='';}); }

  @override Widget build(BuildContext context) {
    if (config == null) return ProvisionScreen(onProvision:_provision,error:error);
    return Scaffold(backgroundColor:const Color(0xfff3f6fa),body:SafeArea(child:Row(children:[
      Expanded(child:Padding(padding:const EdgeInsets.all(36),child:Column(crossAxisAlignment:CrossAxisAlignment.start,mainAxisAlignment:MainAxisAlignment.center,children:[
        const Text('MerdPOS',style:TextStyle(color:Color(0xff0f2742),fontSize:38,fontWeight:FontWeight.w900)),
        const SizedBox(height:24),const Text('Scan to clock in or out',style:TextStyle(color:Color(0xff0b63b6),fontSize:32,fontWeight:FontWeight.w900)),
        const SizedBox(height:14),Text(config!.storeName,style:const TextStyle(fontSize:24,fontWeight:FontWeight.w700)),
        const SizedBox(height:10),Text('QR refreshes in $secondsLeft seconds',style:const TextStyle(color:Color(0xff64748b),fontSize:18)),
        const SizedBox(height:28),TextButton.icon(onPressed:_reset,icon:const Icon(Icons.settings),label:const Text('Re-provision device')),
      ])),
      Container(width:360,color:Colors.white,padding:const EdgeInsets.all(30),child:Center(child:qr.isEmpty?const CircularProgressIndicator():QrImageView(data:qr,size:300,backgroundColor:Colors.white,errorCorrectionLevel:QrErrorCorrectLevel.M))),
    ])));
  }
}

class ProvisionScreen extends StatefulWidget {
  final Future<void> Function(Map<String,String>) onProvision; final String? error;
  const ProvisionScreen({super.key,required this.onProvision,this.error});
  @override State<ProvisionScreen> createState()=>_ProvisionScreenState();
}
class _ProvisionScreenState extends State<ProvisionScreen> {
  final form=GlobalKey<FormState>(); bool busy=false; String? error;
  final fields=<String,TextEditingController>{
    'client_id':TextEditingController(text:'1'),'store_id':TextEditingController(),'device_uuid':TextEditingController(),
    'store_name':TextEditingController(),'backend_url':TextEditingController(text:'https://app.merdpos.com/backend/api'),
    'portal_url':TextEditingController(text:'https://app.merdpos.com/timesheet_portal/scan.php'),'activation_token':TextEditingController(),
  };
  @override Widget build(BuildContext context)=>Scaffold(backgroundColor:const Color(0xfff3f6fa),body:Center(child:SingleChildScrollView(child:Container(width:620,padding:const EdgeInsets.all(30),decoration:BoxDecoration(color:Colors.white,borderRadius:BorderRadius.circular(20)),child:Form(key:form,child:Column(crossAxisAlignment:CrossAxisAlignment.start,children:[
    const Text('Provision attendance display',style:TextStyle(color:Color(0xff0f2742),fontSize:28,fontWeight:FontWeight.w900)),
    const SizedBox(height:8),const Text('Internet is required once. Daily QR rotation is offline.',style:TextStyle(color:Color(0xff64748b))),
    const SizedBox(height:18),Wrap(spacing:12,runSpacing:12,children:fields.entries.map((entry)=>SizedBox(width:entry.key.contains('url')?548:268,child:TextFormField(controller:entry.value,obscureText:entry.key=='activation_token',decoration:InputDecoration(labelText:entry.key.replaceAll('_',' '),border:const OutlineInputBorder()),validator:(v)=>(v??'').trim().isEmpty?'Required':null))).toList()),
    if(error!=null) Padding(padding:const EdgeInsets.only(top:12),child:Text(error!,style:const TextStyle(color:Colors.red))),
    const SizedBox(height:18),FilledButton(onPressed:busy?null:()async{if(!form.currentState!.validate())return;setState(()=>busy=true);try{await widget.onProvision(fields.map((k,v)=>MapEntry(k,v.text.trim())));}catch(e){setState(()=>error=e.toString());}finally{if(mounted)setState(()=>busy=false);}},child:Text(busy?'Provisioning…':'Provision device')),
  ]))))));
}
