part of merdpos_staff;

class _PosSideRail extends StatelessWidget {
  const _PosSideRail({
    required this.primary,
    required this.secondary,
    required this.onPrimaryTimesheet,
    required this.onPrimaryChangePassword,
    required this.onPrimaryLogOff,
    required this.onAddUser,
    required this.onSecondaryLogOff,
    required this.onWhoIsWorking,
    required this.onPos,
    required this.onFinancials,
    required this.onInventory,
    required this.onSync,
    required this.onSettings,
  });

  final Employee primary;
  final Employee? secondary;
  final VoidCallback onPrimaryTimesheet;
  final VoidCallback onPrimaryChangePassword;
  final Future<void> Function() onPrimaryLogOff;
  final Future<void> Function() onAddUser;
  final VoidCallback onSecondaryLogOff;
  final VoidCallback onWhoIsWorking;
  final VoidCallback onPos;
  final VoidCallback onFinancials;
  final VoidCallback onInventory;
  final VoidCallback? onSync;
  final VoidCallback onSettings;

  @override
  Widget build(BuildContext context) {
    final bool hasSecondary = secondary != null;
    return Container(
      width: 82,
      decoration: const BoxDecoration(
        color: BlueIce.bg,
        border: Border(right: BorderSide(color: BlueIce.border)),
      ),
      child: SafeArea(
        child: Column(
          children: [
            const SizedBox(height: 12),
            PopupMenuButton<String>(
              tooltip: 'User menu',
              onSelected: (String value) async {
                if (value == 'timesheet') onPrimaryTimesheet();
                if (value == 'password') onPrimaryChangePassword();
                if (value == 'logout_primary') await onPrimaryLogOff();
                if (value == 'add_user') await onAddUser();
                if (value == 'logout_secondary') onSecondaryLogOff();
              },
              itemBuilder: (_) {
                if (hasSecondary) {
                  return <PopupMenuEntry<String>>[
                    PopupMenuItem<String>(enabled: false, child: Text('${primary.fullName} / ${primary.roleName}')),
                    PopupMenuItem<String>(value: 'logout_primary', child: Text('${primary.shortName} Log off')),
                    const PopupMenuDivider(),
                    PopupMenuItem<String>(enabled: false, child: Text('${secondary!.fullName} / ${secondary!.roleName}')),
                    PopupMenuItem<String>(value: 'logout_secondary', child: Text('${secondary!.shortName} Log off')),
                  ];
                }
                return const <PopupMenuEntry<String>>[
                  PopupMenuItem<String>(value: 'timesheet', child: Text('Time Sheet')),
                  PopupMenuItem<String>(value: 'password', child: Text('Change Password')),
                  PopupMenuItem<String>(value: 'logout_primary', child: Text('Log off')),
                  PopupMenuDivider(),
                  PopupMenuItem<String>(value: 'add_user', child: Text('Add User')),
                ];
              },
              child: _UserSessionAvatar(primary: primary, secondary: secondary),
            ),
            const SizedBox(height: 8),
            Text(hasSecondary ? '2 users' : primary.shortName, textAlign: TextAlign.center, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: BlueIce.textMuted, fontSize: 10)),
            const SizedBox(height: 20),
            _RailItem(icon: Icons.groups, label: 'Who Is Working', onTap: onWhoIsWorking),
            _RailItem(icon: Icons.point_of_sale, label: 'POS', selected: true, onTap: onPos),
            _RailItem(icon: Icons.attach_money, label: 'Financials', onTap: onFinancials),
            _RailItem(icon: Icons.assessment, label: 'Inventory', onTap: onInventory),
            const Spacer(),
            _RailItem(icon: Icons.cloud_sync, label: 'Sync', onTap: onSync),
            _RailItem(icon: Icons.settings, label: 'Settings', onTap: onSettings),
            const SizedBox(height: 8),
          ],
        ),
      ),
    );
  }
}

class _UserSessionAvatar extends StatelessWidget {
  const _UserSessionAvatar({required this.primary, required this.secondary});
  final Employee primary;
  final Employee? secondary;

  @override
  Widget build(BuildContext context) {
    final String p = primary.initial;
    final String? s = secondary?.initial;
    if (s == null) {
      return Container(
        width: 52,
        height: 52,
        decoration: BoxDecoration(shape: BoxShape.circle, color: BlueIce.surface, border: Border.all(color: BlueIce.accent), boxShadow: const [BoxShadow(color: Color(0x595FB6E6), blurRadius: 12)]),
        alignment: Alignment.center,
        child: Text(p, style: const TextStyle(color: BlueIce.text, fontWeight: FontWeight.w900, fontSize: 18)),
      );
    }
    return ClipOval(
      child: Container(
        width: 52,
        height: 52,
        decoration: BoxDecoration(border: Border.all(color: BlueIce.accent), shape: BoxShape.circle),
        child: Row(children: [
          Expanded(child: Container(color: BlueIce.surface, alignment: Alignment.center, child: Text(p, style: const TextStyle(color: BlueIce.text, fontWeight: FontWeight.w900, fontSize: 16)))),
          Expanded(child: Container(color: BlueIce.slate, alignment: Alignment.center, child: Text(s, style: const TextStyle(color: BlueIce.text, fontWeight: FontWeight.w900, fontSize: 16)))),
        ]),
      ),
    );
  }
}

class _RailItem extends StatelessWidget {
  const _RailItem({required this.icon, required this.label, required this.onTap, this.selected = false});
  final IconData icon;
  final String label;
  final VoidCallback? onTap;
  final bool selected;

  @override
  Widget build(BuildContext context) {
    final Color color = selected ? BlueIce.accent : BlueIce.textMuted;
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(10),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 10, horizontal: 6),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Icon(icon, color: color, size: 22),
          const SizedBox(height: 4),
          Text(label, textAlign: TextAlign.center, maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: color, fontSize: 9, fontWeight: FontWeight.w600)),
        ]),
      ),
    );
  }
}
