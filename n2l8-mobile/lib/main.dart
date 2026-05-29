import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_dotenv/flutter_dotenv.dart';
import 'theme/app_theme.dart';
import 'screens/splash_screen.dart';

Future<void> main() async {
  // Ensure widget bindings are initialised securely before engine operations
  WidgetsFlutterBinding.ensureInitialized();
  
  // Load configuration variables from .env file
  try {
    await dotenv.load(fileName: ".env");
  } catch (e) {
    // Fallback if .env is missing or unreadable during dev checks
    // ignore: avoid_print
    print("WARNING: Could not load .env file. Using defaults. Error: $e");
  }

  // Force responsive vertical orientations for optimal terminal UX
  await SystemChrome.setPreferredOrientations([
    DeviceOrientation.portraitUp,
    DeviceOrientation.portraitDown,
  ]);

  // Style the system status bar and navigation drawer elements in immersive dark mode
  SystemChrome.setSystemUIOverlayStyle(const SystemUiOverlayStyle(
    statusBarColor: Colors.transparent,
    statusBarIconBrightness: Brightness.light,
    statusBarBrightness: Brightness.dark,
    systemNavigationBarColor: AppTheme.bgDark,
    systemNavigationBarIconBrightness: Brightness.light,
  ));

  runApp(const N2L8MobileApp());
}

class N2L8MobileApp extends StatelessWidget {
  const N2L8MobileApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'N2L8 STUDIO',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.darkTheme,
      themeMode: ThemeMode.dark, // Enforce dark theme exclusively
      home: const SplashScreen(),
    );
  }
}
