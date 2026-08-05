part of merdpos_staff;

class AppSession {
  AppSession({
    required this.clientId,
    required this.clientName,
    required this.storeId,
    required this.storeName,
    required this.deviceUuid,
    required this.activationToken,
  });

  final int clientId;
  final String clientName;
  final int storeId;
  final String storeName;
  final String deviceUuid;
  final String activationToken;

  static Future<AppSession?> load({DeviceTokenStore? tokenStore}) async {
    final SharedPreferences prefs = await SharedPreferences.getInstance();
    final int? clientId = prefs.getInt('client_id');
    final int? storeId = prefs.getInt('store_id');
    final String? clientName = prefs.getString('client_name');
    final String? storeName = prefs.getString('store_name');
    final String? deviceUuid = prefs.getString('device_uuid');
    final DeviceTokenResult tokenResult = await (tokenStore ?? DeviceTokenStore()).load();
    final String? activationToken = tokenResult.token;
    if (clientId == null || storeId == null || clientName == null || storeName == null || deviceUuid == null || activationToken == null) return null;
    return AppSession(
      clientId: clientId,
      clientName: clientName,
      storeId: storeId,
      storeName: storeName,
      deviceUuid: deviceUuid,
      activationToken: activationToken,
    );
  }

  Future<void> save({DeviceTokenStore? tokenStore}) async {
    await (tokenStore ?? DeviceTokenStore()).save(activationToken);
    final SharedPreferences prefs = await SharedPreferences.getInstance();
    await prefs.setInt('client_id', clientId);
    await prefs.setString('client_name', clientName);
    await prefs.setInt('store_id', storeId);
    await prefs.setString('store_name', storeName);
    await prefs.setString('device_uuid', deviceUuid);
  }

  static Future<void> requireReactivation({DeviceTokenStore? tokenStore}) =>
      (tokenStore ?? DeviceTokenStore()).requireReactivation();
}
