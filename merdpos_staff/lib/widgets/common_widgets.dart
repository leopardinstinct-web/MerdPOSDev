part of merdpos_staff;

class _CenteredShell extends StatelessWidget {
  const _CenteredShell({required this.child});
  final Widget child;
  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: LayoutBuilder(
        builder: (BuildContext context, BoxConstraints constraints) {
          return SingleChildScrollView(
            padding: const EdgeInsets.symmetric(vertical: 10),
            child: ConstrainedBox(
              constraints: BoxConstraints(minHeight: constraints.maxHeight - 20),
              child: Center(
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 410),
                  child: Container(
                    margin: const EdgeInsets.all(10),
                    padding: const EdgeInsets.all(16),
                    decoration: _panelDecoration(radius: 12),
                    child: child,
                  ),
                ),
              ),
            ),
          );
        },
      ),
    );
  }
}

class _CompactLogoHeader extends StatelessWidget {
  const _CompactLogoHeader();

  @override
  Widget build(BuildContext context) {
    return Row(children: [
      Container(
        width: 36,
        height: 36,
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(9),
          color: BlueIce.slate,
          border: Border.all(color: BlueIce.accent),
        ),
        child: const Icon(Icons.hexagon_outlined, color: BlueIce.accent, size: 21),
      ),
      const SizedBox(width: 10),
      RichText(
        text: const TextSpan(children: [
          TextSpan(text: 'Merd', style: TextStyle(color: BlueIce.text, fontSize: 25, fontWeight: FontWeight.w800)),
          TextSpan(text: 'POS', style: TextStyle(color: BlueIce.brandBlue, fontSize: 25, fontWeight: FontWeight.w500)),
        ]),
      ),
    ]);
  }
}

class _LogoHeader extends StatelessWidget {
  const _LogoHeader({required this.title, required this.subtitle});

  final String title;
  final String subtitle;

  @override
  Widget build(BuildContext context) {
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      const _CompactLogoHeader(),
      const SizedBox(height: 18),
      Text(title, style: Theme.of(context).textTheme.displaySmall),
      const SizedBox(height: 6),
      Text(subtitle, style: const TextStyle(color: BlueIce.textMuted, height: 1.35)),
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
        Text(subtitle, style: const TextStyle(color: BlueIce.textMuted, fontWeight: FontWeight.w600)),
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
    return Container(padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7), decoration: BoxDecoration(color: BlueIce.slate, borderRadius: BorderRadius.circular(999), border: Border.all(color: BlueIce.border)), child: Row(mainAxisSize: MainAxisSize.min, children: [Icon(icon, size: 15, color: BlueIce.accent), const SizedBox(width: 6), Text(label, style: const TextStyle(color: BlueIce.text, fontSize: 12, fontWeight: FontWeight.w700))]));
  }
}

enum MessageType { info, error }
enum _CredentialField { userId, password }

class _MessageCard extends StatelessWidget {
  const _MessageCard({required this.message, required this.type});
  final String message;
  final MessageType type;
  @override
  Widget build(BuildContext context) {
    final Color color = type == MessageType.error ? BlueIce.error : BlueIce.success;
    return Container(padding: const EdgeInsets.all(12), decoration: BoxDecoration(color: BlueIce.slate, borderRadius: BorderRadius.circular(10), border: Border.all(color: color)), child: Row(children: [Icon(type == MessageType.error ? Icons.error_outline : Icons.info_outline, color: color), const SizedBox(width: 8), Expanded(child: Text(message, style: const TextStyle(color: BlueIce.text)))]));
  }
}

class _NumericLoginField extends StatelessWidget {
  const _NumericLoginField({
    required this.controller,
    required this.label,
    required this.selected,
    required this.enabled,
    required this.onSelected,
    this.obscure = false,
  });

  final TextEditingController controller;
  final String label;
  final bool selected;
  final bool enabled;
  final VoidCallback onSelected;
  final bool obscure;

  @override
  Widget build(BuildContext context) {
    final Color borderColor = selected ? BlueIce.accent : BlueIce.border;
    return TextField(
      controller: controller,
      readOnly: true,
      showCursor: false,
      enableInteractiveSelection: false,
      keyboardType: TextInputType.none,
      obscureText: obscure,
      enabled: enabled,
      onTap: onSelected,
      style: const TextStyle(color: BlueIce.text, fontWeight: FontWeight.w700, letterSpacing: 0.4),
      decoration: InputDecoration(
        isDense: true,
        contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 13),
        labelText: label,
        suffixIcon: Icon(selected ? Icons.radio_button_checked : Icons.radio_button_unchecked, color: borderColor, size: 18),
        suffixIconConstraints: const BoxConstraints(minWidth: 38, minHeight: 38),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(10),
          borderSide: BorderSide(color: borderColor, width: selected ? 1.5 : 1),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(10),
          borderSide: const BorderSide(color: BlueIce.accent, width: 1.5),
        ),
      ),
    );
  }
}

class _LoginNumericPad extends StatelessWidget {
  const _LoginNumericPad({
    required this.enabled,
    required this.onDigit,
    required this.onBackspace,
    required this.onClear,
  });

  final bool enabled;
  final ValueChanged<String> onDigit;
  final VoidCallback onBackspace;
  final VoidCallback onClear;

  @override
  Widget build(BuildContext context) {
    return GridView.count(
      crossAxisCount: 3,
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      mainAxisSpacing: 6,
      crossAxisSpacing: 6,
      childAspectRatio: 2.35,
      children: [
        for (final String digit in const ['1', '2', '3', '4', '5', '6', '7', '8', '9']) _padButton(label: digit, onPressed: enabled ? () => onDigit(digit) : null),
        _padButton(label: 'Clear', compact: true, onPressed: enabled ? onClear : null),
        _padButton(label: '0', onPressed: enabled ? () => onDigit('0') : null),
        _padButton(icon: Icons.backspace_outlined, onPressed: enabled ? onBackspace : null),
      ],
    );
  }

  Widget _padButton({String? label, IconData? icon, required VoidCallback? onPressed, bool compact = false}) {
    return OutlinedButton(
      onPressed: onPressed,
      style: OutlinedButton.styleFrom(
        foregroundColor: BlueIce.text,
        backgroundColor: BlueIce.surface,
        disabledForegroundColor: BlueIce.textMuted.withOpacity(0.45),
        side: const BorderSide(color: BlueIce.border),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
        padding: EdgeInsets.zero,
        textStyle: TextStyle(fontSize: compact ? 12 : 20, fontWeight: FontWeight.w800),
      ),
      child: icon == null ? Text(label ?? '') : Icon(icon, size: 20),
    );
  }
}
