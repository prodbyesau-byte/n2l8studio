import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../theme/app_theme.dart';

class OfflineScreen extends StatelessWidget {
  final VoidCallback onRetry;

  const OfflineScreen({super.key, required this.onRetry});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppTheme.bgDark,
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 32.0, vertical: 24.0),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Spacer(),
              
              // WARNING HAZARD LOGO
              Center(
                child: Container(
                  padding: const EdgeInsets.all(24),
                  decoration: BoxDecoration(
                    color: AppTheme.accentAmber.withOpacity(0.04),
                    shape: BoxShape.circle,
                    border: Border.all(color: AppTheme.accentAmber, width: 2),
                    boxShadow: [
                      BoxShadow(
                        color: AppTheme.accentAmber.withOpacity(0.1),
                        blurRadius: 20,
                        spreadRadius: 1,
                      )
                    ],
                  ),
                  child: const Icon(
                    Icons.wifi_off,
                    color: AppTheme.accentAmber,
                    size: 64,
                  ),
                ),
              ),
              
              const SizedBox(height: 32),
              
              // WARNING TERMINAL HEADER
              Center(
                child: Text(
                  "!!! CRITICAL FAILURE !!!",
                  style: GoogleFonts.righteous(
                    color: AppTheme.accentAmber,
                    fontSize: 24,
                    letterSpacing: 1.0,
                    shadows: AppTheme.amberGlow,
                  ),
                ),
              ),
              
              const SizedBox(height: 16),
              
              // TERMINAL BOX DESCRIPTION
              Container(
                padding: const EdgeInsets.all(20),
                decoration: BoxDecoration(
                  color: Colors.black,
                  border: Border.all(color: AppTheme.accentAmber.withOpacity(0.3)),
                  borderRadius: BorderRadius.circular(4),
                ),
                child: Column(
                  children: [
                    Text(
                      "CONNECTION LOST",
                      style: GoogleFonts.vt323(
                        color: AppTheme.accentAmber,
                        fontSize: 20,
                        letterSpacing: 1.0,
                      ),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      "THE N2L8 MAINFRAME CANNOT BE COMPROMISED. RE-ESTABLISH DATA PIPELINES TO ACCESS MUSIC, FORUMS, AND SECURE NET CHANNELS.",
                      textAlign: TextAlign.center,
                      style: GoogleFonts.vt323(
                        color: AppTheme.accentAmber.withOpacity(0.8),
                        fontSize: 14,
                        height: 1.4,
                      ),
                    ),
                  ],
                ),
              ),
              
              const Spacer(),
              
              // GLOWING RETRY BUTTON
              OutlinedButton(
                onPressed: onRetry,
                style: OutlinedButton.styleFrom(
                  side: const BorderSide(color: AppTheme.textMain, width: 2),
                  padding: const EdgeInsets.symmetric(vertical: 16),
                  backgroundColor: AppTheme.textMain.withOpacity(0.05),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(4),
                  ),
                  foregroundColor: AppTheme.textMain,
                  shadowColor: AppTheme.textMain,
                  elevation: 6,
                ),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    const Icon(Icons.sync, color: AppTheme.textMain),
                    const SizedBox(width: 12),
                    Text(
                      "REBOOT LINK PORT",
                      style: GoogleFonts.righteous(
                        fontSize: 16,
                        letterSpacing: 1.5,
                        shadows: AppTheme.phosphorGlow,
                      ),
                    ),
                  ],
                ),
              ),
              
              const SizedBox(height: 16),
              
              Center(
                child: Text(
                  "PORT STATUS: LISTENING ON PORT 8080",
                  style: GoogleFonts.vt323(
                    color: Colors.white24,
                    fontSize: 12,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
