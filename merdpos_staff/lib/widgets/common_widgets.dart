part of merdpos_staff;

class _CenteredShell extends StatelessWidget {
  const _CenteredShell({required this.child});
  final Widget child;
  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 460),
          child: Container(margin: const EdgeInsets.all(20), padding: const EdgeInsets.all(24), decoration: _panelDecoration(radius: 14), child: child),
        ),
      ),
    );
  }
}

class _LogoHeader extends StatelessWidget {
  const _LogoHeader({required this.title, required this.subtitle});
  final String title;
  final String subtitle;
  @override
  Widget build(BuildContext context) {
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Row(children: [
        Container(width: 44, height: 44, decoration: BoxDecoration(borderRadius: BorderRadius.circular(10), color: BlueIce.bg, border: Border.all(color: BlueIce.accent), boxShadow: const [BoxShadow(color: Color(0x595FB6E6), blurRadius: 12)]), child: const Icon(Icons.hexagon_outlined, color: BlueIce.accent)),
        const SizedBox(width: 12),
        RichText(text: const TextSpan(children: [TextSpan(text: 'Merd', style: TextStyle(color: BlueIce.text, fontSize: 28, fontWeight: FontWeight.w800)), TextSpan(text: 'POS', style: TextStyle(color: BlueIce.brandBlue, fontSize: 28, fontWeight: FontWeight.w500))])),
      ]),
      const SizedBox(height: 18),
      Text(title, style: Theme.of(context).textTheme.headlineSmall),
      const SizedBox(height: 6),
      Text(subtitle, style: Theme.of(context).textTheme.bodySmall),
    ]);
  }
}

class _ScreenHeader extends StatelessWidget {
  const _ScreenHeader({required this.storeName, required this.title, required this.subtitle});
  final String storeName;
  final String title;
  final String subtitle;
  @override
  Widget build(BuildContext context) {
    return Row(children: [
      Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(storeName, style: const TextStyle(color: BlueIce.textMuted, fontSize: 13, fontWeight: FontWeight.w600)),
        const SizedBox(height: 4),
        Text(title, style: Theme.of(context).textTheme.displaySmall),
        const SizedBox(height: 4),
        Text(subtitle, style: const TextStyle(color: BlueIce.brandBlue, fontWeight: FontWeight.w600)),
      ])),
      Text(_timeOnly(DateTime.now()).substring(0, 5), style: Theme.of(context).textTheme.titleMedium?.copyWith(color: BlueIce.textMuted)),
    ]);
  }
}

class _MetricCard extends StatelessWidget {
  const _MetricCard({required this.icon, required this.label, required this.value});
  final IconData icon;
  final String label;
  final String value;
  @override
  Widget build(BuildContext context) {
    return Container(width: 230, padding: const EdgeInsets.all(16), decoration: _panelDecoration(), child: Row(children: [
      Icon(icon, color: BlueIce.accent),
      const SizedBox(width: 12),
      Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(label, style: const TextStyle(color: BlueIce.textMuted, fontSize: 12)), const SizedBox(height: 3), Text(value, overflow: TextOverflow.ellipsis, style: const TextStyle(color: BlueIce.text, fontWeight: FontWeight.w700))])),
    ]));
  }
}

class _MetricPill extends StatelessWidget {
  const _MetricPill({required this.icon, required this.label});
  final IconData icon;
  final String label;
  @override
  Widget build(BuildContext context) {
    return Container(padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7), decoration: BoxDecoration(color: BlueIce.bg, borderRadius: BorderRadius.circular(999), border: Border.all(color: BlueIce.border)), child: Row(mainAxisSize: MainAxisSize.min, children: [Icon(icon, size: 15, color: BlueIce.accent), const SizedBox(width: 6), Text(label, style: const TextStyle(color: BlueIce.text, fontSize: 12, fontWeight: FontWeight.w700))]));
  }
}

enum MessageType { info, error }

class _MessageCard extends StatelessWidget {
  const _MessageCard({required this.message, required this.type});
  final String message;
  final MessageType type;
  @override
  Widget build(BuildContext context) {
    final Color color = type == MessageType.error ? BlueIce.error : BlueIce.success;
    return Container(padding: const EdgeInsets.all(12), decoration: BoxDecoration(color: BlueIce.bg, borderRadius: BorderRadius.circular(10), border: Border.all(color: color)), child: Row(children: [Icon(type == MessageType.error ? Icons.error_outline : Icons.info_outline, color: color), const SizedBox(width: 8), Expanded(child: Text(message, style: const TextStyle(color: BlueIce.text)))]));
  }
}
