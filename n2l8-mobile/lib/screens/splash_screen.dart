import 'dart:async';
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../theme/app_theme.dart';
import 'dashboard_screen.dart';

class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key});

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen> with SingleTickerProviderStateMixin {
  late AnimationController _fadeController;
  late Animation<double> _fadeAnimation;
  
  final List<String> _bootLogs = [];
  int _logIndex = 0;
  
  final List<String> _rawLogs = [
    "> N2L8 BIOS v2.0.26 INIT...",
    "> RESOLVING TARGET HOSTS...",
    "> CONNECTING SECURE GATEWAY [n2l8studio.dk]...",
    "> LOADING AUDIO MODULATORS...",
    "> SEEDING COMMUNITY PLUGINS...",
    "> AUTH TOKENS DETECTED & VERIFIED...",
    "> SYSTEM READY: INITIALIZING CONSOLE...",
  ];

  @override
  void initState() {
    super.initState();
    
    // Fade & Pulse animation for logo
    _fadeController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1500),
    )..repeat(reverse: true);
    
    _fadeAnimation = Tween<double>(begin: 0.4, end: 1.0).animate(
      CurvedAnimation(parent: _fadeController, curve: Curves.easeInOut),
    );

    // Boot terminal print effect
    _printNextLog();
  }

  void _printNextLog() {
    if (_logIndex < _rawLogs.length) {
      setState(() {
        _bootLogs.add(_rawLogs[_logIndex]);
      });
      _logIndex++;
      
      // Delay before printing next line
      Timer(Duration(milliseconds: 350 + (_logIndex * 50)), _printNextLog);
    } else {
      // Completed boot sequence, proceed to main dashboard screen
      Timer(const Duration(milliseconds: 800), _navigateToDashboard);
    }
  }

  void _navigateToDashboard() {
    Navigator.of(context).pushReplacement(
      PageRouteBuilder(
        pageBuilder: (context, animation, secondaryAnimation) => const DashboardScreen(),
        transitionsBuilder: (context, animation, secondaryAnimation, child) {
          return FadeTransition(
            opacity: animation,
            child: child,
          );
        },
        transitionDuration: const Duration(milliseconds: 600),
      ),
    );
  }

  @override
  void dispose() {
    _fadeController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final media = MediaQuery.of(context);
    return Scaffold(
      backgroundColor: AppTheme.bgDark,
      body: Stack(
        children: [
          // SCANLINE CRT GLOW EFFECT
          Positioned.fill(
            child: Container(
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topCenter,
                  end: Alignment.bottomCenter,
                  colors: [
                    Colors.black,
                    AppTheme.textMain.withOpacity(0.015),
                    Colors.black,
                  ],
                  stops: const [0.0, 0.5, 1.0],
                ),
              ),
            ),
          ),
          
          // CRT HORIZONTAL LINES
          IgnorePointer(
            child: Container(
              decoration: BoxDecoration(
                image: DecorationImage(
                  image: const AssetImage('assets/mascot.png'),
                  fit: BoxFit.cover,
                  colorFilter: ColorFilter.mode(
                    Colors.black.withOpacity(0.975), 
                    BlendMode.darken
                  ),
                ),
              ),
            ),
          ),
          
          SafeArea(
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 24.0, vertical: 16.0),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const Spacer(),
                  
                  // LOGO CONTAINER WITH AMBER GLOW FRAME
                  Center(
                    child: FadeTransition(
                      opacity: _fadeAnimation,
                      child: Container(
                        padding: const EdgeInsets.all(16),
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          border: Border.all(color: AppTheme.textMain, width: 2),
                          boxShadow: [
                            BoxShadow(
                              color: AppTheme.textMain.withOpacity(0.15),
                              blurRadius: 20,
                              spreadRadius: 2,
                            ),
                          ],
                        ),
                        child: Image.asset(
                          'assets/logo.png',
                          width: 100,
                          height: 100,
                          fit: BoxFit.contain,
                          errorBuilder: (context, error, stackTrace) {
                            // Fallback in case image asset is missing locally
                            return const Icon(
                              Icons.terminal, 
                              color: AppTheme.textMain, 
                              size: 80
                            );
                          },
                        ),
                      ),
                    ),
                  ),
                  
                  const SizedBox(height: 24),
                  
                  Center(
                    child: Text(
                      "N2L8 STUDIO",
                      style: GoogleFonts.righteous(
                        color: Colors.white,
                        fontSize: 28,
                        letterSpacing: 2.0,
                        shadows: AppTheme.phosphorGlow,
                      ),
                    ),
                  ),
                  
                  Center(
                    child: Text(
                      "PREMIUM AUDIO CONSOLE",
                      style: GoogleFonts.vt323(
                        color: AppTheme.accentAmber,
                        fontSize: 16,
                        letterSpacing: 1.5,
                      ),
                    ),
                  ),
                  
                  const Spacer(),
                  
                  // Monospace Boot Logger Output
                  Container(
                    height: 160,
                    padding: const EdgeInsets.all(16),
                    decoration: BoxDecoration(
                      color: Colors.black,
                      borderRadius: BorderRadius.circular(6),
                      border: Border.all(color: AppTheme.borderGreen),
                    ),
                    child: ListView.builder(
                      physics: const NeverScrollableScrollPhysics(),
                      itemCount: _bootLogs.length,
                      itemBuilder: (context, index) {
                        final isLast = index == _bootLogs.length - 1;
                        return Padding(
                          padding: const EdgeInsets.only(bottom: 4.0),
                          child: Text(
                            _bootLogs[index] + (isLast ? " _" : ""),
                            style: GoogleFonts.vt323(
                              color: isLast ? AppTheme.textMain : AppTheme.textMain.withOpacity(0.6),
                              fontSize: 14,
                              letterSpacing: 0.5,
                            ),
                          ),
                        );
                      },
                    ),
                  ),
                  
                  const SizedBox(height: 20),
                  
                  // Bottom Footer info
                  Center(
                    child: Text(
                      "© 2026 N2L8STUDIO. ALL SYSTEMS OPERATIONAL.",
                      style: GoogleFonts.vt323(
                        color: Colors.white24,
                        fontSize: 12,
                        letterSpacing: 0.5,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}
