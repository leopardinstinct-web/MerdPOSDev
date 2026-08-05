# DESIGN_TOKENS.md — Blue Ice (canonical)

Machine-usable tokens for MerdPOS. This file is the source of truth for color,
type, spacing, and radius. The design brief explains intent; these are the
exact values. Apply them in Flutter through theme.dart — never hardcode
colors in widgets.

Color tokens
text
--bg              #080C14   app background
--surface         #0E1626   cards / panels
--border          #1C2A40   outlines, dividers, table grid
--text            #FFFFFF   primary text, headings
--text-muted      #9FB3C8   labels, captions
--brand-blue      #88C0D0   "POS", accents, large accent text
--accent-glow     #5FB6E6   interactive, highlights, hover glow
--slate           #434C5E   subtle background visuals (neural/grid)
--success         #5FD0C5   confirmations (sparingly) — replaces standard green
--warning         #E0C56B   reserved, rare
--error           #E06C9F   cool red/magenta only — replaces standard red
Type scale
text
font-family       Montserrat / Metropolis, sans-serif fallback
display           28 / 700   screen titles
heading           22 / 600   section headers
title             18 / 600   card titles
body              15 / 400   default text
label             13 / 500   form labels, table headers
caption           12 / 400   muted captions
Spacing (4pt base)
text
xs 4   sm 8   md 12   lg 16   xl 24   2xl 32   3xl 48
Radius
text
sm 6   md 10   lg 14   pill 999
Elevation / glow
text
glow-accent   0 0 12px rgba(95,182,230,0.35)   focus/hover on interactive
shadow-card   0 2px 8px rgba(0,0,0,0.45)
Flutter theme mapping (merdpos_staff/lib/theme.dart)
Drop-in starting point. Keep token names in sync with the list above.

dart
import 'package:flutter/material.dart';

class BlueIce {
  static const bg        = Color(0xFF080C14);
  static const surface   = Color(0xFF0E1626);
  static const border    = Color(0xFF1C2A40);
  static const text      = Color(0xFFFFFFFF);
  static const textMuted = Color(0xFF9FB3C8);
  static const brandBlue = Color(0xFF88C0D0);
  static const accent    = Color(0xFF5FB6E6);
  static const slate     = Color(0xFF434C5E);
  static const success   = Color(0xFF5FD0C5);
  static const warning   = Color(0xFFE0C56B);
  static const error     = Color(0xFFE06C9F);
}

ThemeData blueIceTheme() {
  const base = TextStyle(color: BlueIce.text, fontFamily: 'Montserrat');
  return ThemeData(
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
    cardTheme: CardTheme(
      color: BlueIce.surface,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(10),
        side: const BorderSide(color: BlueIce.border),
      ),
    ),
    textTheme: TextTheme(
      displaySmall:  base.copyWith(fontSize: 28, fontWeight: FontWeight.w700),
      headlineSmall: base.copyWith(fontSize: 22, fontWeight: FontWeight.w600),
      titleMedium:   base.copyWith(fontSize: 18, fontWeight: FontWeight.w600),
      bodyMedium:    base.copyWith(fontSize: 15, fontWeight: FontWeight.w400),
      labelMedium:   base.copyWith(fontSize: 13, fontWeight: FontWeight.w500,
                                   color: BlueIce.textMuted),
      bodySmall:     base.copyWith(fontSize: 12, color: BlueIce.textMuted),
    ),
    elevatedButtonTheme: ElevatedButtonThemeData(
      style: ElevatedButton.styleFrom(
        backgroundColor: BlueIce.accent,
        foregroundColor: BlueIce.bg,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(10)),
      ),
    ),
  );
}
Usage: MaterialApp(theme: blueIceTheme(), ...). Add the Montserrat font to
pubspec.yaml (or use google_fonts) so the family resolves.

Reminder: When porting UI from competitors, replace any standard green with
#5FD0C5 and any standard red with #E06C9F. Keep the cool palette intact.
