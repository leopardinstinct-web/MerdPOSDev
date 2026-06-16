part of merdpos_staff;

class BlueIce {
  static const Color bg = Color(0xFF080C14);
  static const Color surface = Color(0xFF0E1626);
  static const Color border = Color(0xFF1C2A40);
  static const Color text = Color(0xFFFFFFFF);
  static const Color textMuted = Color(0xFF9FB3C8);
  static const Color brandBlue = Color(0xFF88C0D0);
  static const Color accent = Color(0xFF5FB6E6);
  static const Color slate = Color(0xFF434C5E);
  static const Color success = Color(0xFF5FD0C5);
  static const Color warning = Color(0xFFE0C56B);
  static const Color error = Color(0xFFE06C9F);
}

ThemeData blueIceTheme() {
  const TextStyle base = TextStyle(color: BlueIce.text, fontFamily: 'Montserrat');
  return ThemeData(
    useMaterial3: true,
    brightness: Brightness.dark,
    scaffoldBackgroundColor: BlueIce.bg,
    canvasColor: BlueIce.bg,
    colorScheme: const ColorScheme.dark(
      surface: BlueIce.surface,
      primary: BlueIce.accent,
      secondary: BlueIce.brandBlue,
      error: BlueIce.error,
      onPrimary: BlueIce.bg,
      onSurface: BlueIce.text,
    ),
    dividerColor: BlueIce.border,
    cardTheme: CardThemeData(
      color: BlueIce.surface,
      elevation: 0,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(10),
        side: const BorderSide(color: BlueIce.border),
      ),
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: BlueIce.surface,
      labelStyle: const TextStyle(color: BlueIce.textMuted),
      hintStyle: const TextStyle(color: BlueIce.textMuted),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: const BorderSide(color: BlueIce.border),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: const BorderSide(color: BlueIce.accent, width: 1.3),
      ),
      errorBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: const BorderSide(color: BlueIce.error),
      ),
      focusedErrorBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: const BorderSide(color: BlueIce.error, width: 1.3),
      ),
    ),
    appBarTheme: const AppBarTheme(
      backgroundColor: BlueIce.bg,
      foregroundColor: BlueIce.text,
      elevation: 0,
      centerTitle: false,
    ),
    dialogTheme: DialogThemeData(
      backgroundColor: BlueIce.surface,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(14),
        side: const BorderSide(color: BlueIce.border),
      ),
    ),
    popupMenuTheme: PopupMenuThemeData(
      color: BlueIce.surface,
      textStyle: base.copyWith(fontSize: 13),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(10),
        side: const BorderSide(color: BlueIce.border),
      ),
    ),
    snackBarTheme: const SnackBarThemeData(
      backgroundColor: BlueIce.surface,
      contentTextStyle: TextStyle(color: BlueIce.text),
    ),
    textTheme: TextTheme(
      displaySmall: base.copyWith(fontSize: 28, fontWeight: FontWeight.w700),
      headlineSmall: base.copyWith(fontSize: 22, fontWeight: FontWeight.w600),
      titleMedium: base.copyWith(fontSize: 18, fontWeight: FontWeight.w600),
      bodyMedium: base.copyWith(fontSize: 15, fontWeight: FontWeight.w400),
      labelMedium: base.copyWith(fontSize: 13, fontWeight: FontWeight.w500, color: BlueIce.textMuted),
      bodySmall: base.copyWith(fontSize: 12, color: BlueIce.textMuted),
    ),
    elevatedButtonTheme: ElevatedButtonThemeData(
      style: ElevatedButton.styleFrom(
        backgroundColor: BlueIce.accent,
        foregroundColor: BlueIce.bg,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
      ),
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        backgroundColor: BlueIce.accent,
        foregroundColor: BlueIce.bg,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
      ),
    ),
    textButtonTheme: TextButtonThemeData(
      style: TextButton.styleFrom(foregroundColor: BlueIce.brandBlue),
    ),
  );
}
