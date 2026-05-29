import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

class AppTheme {
  // Brand Color Palette
  static const Color bgDark = Color(0xFF070A07);       // Deep Fallout Terminal Black
  static const Color bgCard = Color(0xFF121812);       // Dark Phosphor-Green Tint Card
  static const Color textMain = Color(0xFF33FF99);     // Radiant Green Phosphor
  static const Color accentAmber = Color(0xFFFFC25C);  // High-Tech Alert Amber
  static const Color accentRose = Color(0xFFA44A5E);   // Sophisticated Deep Magenta/Rose
  static const Color borderGreen = Color(0xFF1C3A22);  // Muted Green Border

  static ThemeData get darkTheme {
    return ThemeData(
      brightness: Brightness.dark,
      primaryColor: textMain,
      scaffoldBackgroundColor: bgDark,
      cardColor: bgCard,
      dividerColor: borderGreen,
      
      // Floating Action Button
      floatingActionButtonTheme: const FloatingActionButtonThemeData(
        backgroundColor: textMain,
        foregroundColor: bgDark,
        elevation: 8,
      ),

      // Text Theme
      textTheme: TextTheme(
        displayLarge: GoogleFonts.righteous(
          color: Colors.white,
          fontSize: 32,
          fontWeight: FontWeight.bold,
          letterSpacing: 1.5,
        ),
        headlineLarge: GoogleFonts.righteous(
          color: textMain,
          fontSize: 24,
          fontWeight: FontWeight.bold,
          letterSpacing: 1.0,
        ),
        titleLarge: GoogleFonts.righteous(
          color: Colors.white,
          fontSize: 20,
          fontWeight: FontWeight.w600,
        ),
        bodyLarge: GoogleFonts.montserrat(
          color: Colors.white70,
          fontSize: 16,
          fontWeight: FontWeight.w500,
          letterSpacing: 0.5,
        ),
        bodyMedium: GoogleFonts.montserrat(
          color: Colors.white60,
          fontSize: 14,
          fontWeight: FontWeight.w400,
        ),
        // Monospace code styles
        bodySmall: GoogleFonts.vt323(
          color: textMain,
          fontSize: 16,
          letterSpacing: 1.0,
        ),
      ),

      // Navigation Bar styling
      bottomNavigationBarTheme: const BottomNavigationBarThemeData(
        backgroundColor: bgDark,
        selectedItemColor: textMain,
        unselectedItemColor: Color(0xFF4A6F52),
        selectedLabelStyle: TextStyle(fontWeight: FontWeight.w600, fontSize: 11),
        unselectedLabelStyle: TextStyle(fontWeight: FontWeight.w400, fontSize: 10),
        type: BottomNavigationBarType.fixed,
        elevation: 16,
      ),

      // Glowing Dialogs
      dialogTheme: DialogTheme(
        backgroundColor: bgCard,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(8),
          side: const BorderSide(color: textMain, width: 1.5),
        ),
      ),
    );
  }

  // Text Shadow Effect simulating screen glow
  static List<Shadow> get phosphorGlow => [
    Shadow(
      blurRadius: 10.0,
      color: textMain.withOpacity(0.55),
      offset: const Offset(0, 0),
    ),
  ];

  static List<Shadow> get amberGlow => [
    Shadow(
      blurRadius: 10.0,
      color: accentAmber.withOpacity(0.55),
      offset: const Offset(0, 0),
    ),
  ];
}
