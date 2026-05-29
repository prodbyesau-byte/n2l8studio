import 'dart:async';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_inappwebview/flutter_inappwebview.dart';
import 'package:flutter_dotenv/flutter_dotenv.dart';
import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:url_launcher/url_launcher.dart';
import '../theme/app_theme.dart';
import 'offline_screen.dart';

class DashboardScreen extends StatefulWidget {
  const DashboardScreen({super.key});

  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  InAppWebViewController? _webViewController;
  PullToRefreshController? _pullToRefreshController;
  
  bool _isLoading = true;
  double _progress = 0;
  bool _isOffline = false;
  int _currentTab = 0;
  
  final String _baseUrl = dotenv.get('BASE_URL');
  final String _uaSuffix = dotenv.get('USER_AGENT_SUFFIX');
  
  late StreamSubscription<List<ConnectivityResult>> _connectivitySubscription;
  final Connectivity _connectivity = Connectivity();

  // Injected CSS to strip out standard web headers, menus, and footers
  final String _appCustomCss = """
    /* Hide top nav and hamburger menus */
    header.hero nav, 
    nav, 
    .nav-hamburger, 
    #navLinks,
    .dropdown { 
      display: none !important; 
    }
    
    /* Hide standard site footer */
    footer, 
    .footer { 
      display: none !important; 
    }
    
    /* Adjust spacing since nav is hidden */
    header.hero { 
      min-height: auto !important; 
      padding-top: 2rem !important; 
      padding-bottom: 2rem !important; 
    }
    
    /* Adjust portal and login cards */
    .login-box { 
      margin: 20px auto !important; 
    }
    
    /* Hide forum breadcrumbs inside app as we have native UI context */
    .forum-breadcrumb { 
      display: none !important; 
    }
    
    /* Prevent webview content from overlapping the native bottom navigation bar */
    body { 
      padding-bottom: 75px !important; 
    }
  """;

  @override
  void initState() {
    super.initState();
    
    // Set up network connection monitor
    _checkInitialConnectivity();
    _connectivitySubscription = _connectivity.onConnectivityChanged.listen(_updateConnectionStatus);

    // Set up pull to refresh controller
    _pullToRefreshController = kIsWeb
        ? null
        : PullToRefreshController(
            settings: PullToRefreshSettings(
              color: AppTheme.textMain,
              backgroundColor: AppTheme.bgDark,
            ),
            onRefresh: () async {
              if (_webViewController != null) {
                _webViewController!.reload();
              }
            },
          );
  }

  @override
  void dispose() {
    _connectivitySubscription.cancel();
    super.dispose();
  }

  Future<void> _checkInitialConnectivity() async {
    final results = await _connectivity.checkConnectivity();
    _updateConnectionStatus(results);
  }

  void _updateConnectionStatus(List<ConnectivityResult> results) {
    // If list is empty or contains only .none, we are offline
    final offline = results.isEmpty || (results.length == 1 && results.first == ConnectivityResult.none);
    setState(() {
      _isOffline = offline;
    });
    
    // Reload webview once connection is re-established
    if (!offline && _isLoading && _webViewController != null) {
      _webViewController!.reload();
    }
  }

  // Intercept physical back buttons (Android) to browse history
  Future<bool> _onWillPop() async {
    if (_webViewController != null && await _webViewController!.canGoBack()) {
      _webViewController!.goBack();
      return false; // Prevent closing app
    }
    return true; // Close app
  }

  // Sync Bottom Navigation Index with current Webview URL paths
  void _syncNavigationTab(String url) {
    setState(() {
      if (url.contains('/shop.php') || url.contains('/beats.php') || url.contains('/graphics.php')) {
        _currentTab = 1;
      } else if (url.contains('/forum.php')) {
        _currentTab = 2;
      } else if (url.contains('/portal/') || url.contains('/login.php') || url.contains('/register.php')) {
        _currentTab = 3;
      } else if (url.endsWith('/index.php') || url == '$_baseUrl/' || url == _baseUrl) {
        _currentTab = 0;
      }
    });
  }

  // Handle click on native bottom tabs
  void _onBottomTabTap(int index) {
    if (_webViewController == null || _isOffline) return;
    
    String targetPath = '/index.php';
    switch (index) {
      case 0:
        targetPath = '/index.php';
        break;
      case 1:
        targetPath = '/shop.php';
        break;
      case 2:
        targetPath = '/forum.php';
        break;
      case 3:
        targetPath = '/portal/index.php';
        break;
    }
    
    setState(() {
      _currentTab = index;
      _isLoading = true;
    });
    
    _webViewController!.loadUrl(
      urlRequest: URLRequest(url: WebUri('$_baseUrl$targetPath')),
    );
  }

  @override
  Widget build(BuildContext context) {
    // Wrap with PopScope to intercept Android back gestures
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, result) async {
        if (didPop) return;
        final shouldPop = await _onWillPop();
        if (shouldPop && context.mounted) {
          Navigator.of(context).pop();
        }
      },
      child: Scaffold(
        backgroundColor: AppTheme.bgDark,
        // Premium Monospace Phosphor AppBar
        appBar: AppBar(
          backgroundColor: AppTheme.bgDark,
          elevation: 0,
          centerTitle: true,
          title: Text(
            "N2L8 STUDIO",
            style: GoogleFonts.righteous(
              color: Colors.white,
              fontSize: 18,
              letterSpacing: 2.0,
              shadows: AppTheme.phosphorGlow,
            ),
          ),
          leading: FutureBuilder<bool>(
            future: _webViewController?.canGoBack() ?? Future.value(false),
            builder: (context, snapshot) {
              final canGoBack = snapshot.data ?? false;
              if (canGoBack) {
                return IconButton(
                  icon: const Icon(Icons.arrow_back_ios_new, color: AppTheme.textMain, size: 20),
                  onPressed: () => _webViewController?.goBack(),
                );
              }
              return Container();
            },
          ),
          actions: [
            IconButton(
              icon: const Icon(Icons.sync, color: AppTheme.textMain, size: 20),
              onPressed: () => _webViewController?.reload(),
            ),
          ],
          bottom: PreferredSize(
            preferredSize: const Size.fromHeight(2.0),
            child: Container(
              color: AppTheme.borderGreen,
              height: 1.0,
            ),
          ),
        ),
        
        body: _isOffline 
            ? OfflineScreen(onRetry: _checkInitialConnectivity) 
            : Stack(
                children: [
                  // MAIN INAPPWEBVIEW SYSTEM
                  InAppWebView(
                    initialUrlRequest: URLRequest(url: WebUri('$_baseUrl/index.php')),
                    pullToRefreshController: _pullToRefreshController,
                    initialSettings: InAppWebViewSettings(
                      useShouldOverrideUrlLoading: true,
                      mediaPlaybackRequiresUserGesture: false,
                      allowsInlineMediaPlayback: true,
                      useOnDownloadStart: true,
                      supportZoom: false,
                      // Append User-Agent suffix for backend integration
                      userAgent: "Mozilla/5.0 (Linux; Android 10) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/100.0.0.0 Mobile Safari/537.36 $_uaSuffix",
                      // Allow persistent cookie capabilities
                      thirdPartyCookiesEnabled: true,
                      sharedCookiesEnabled: true,
                    ),
                    
                    onWebViewCreated: (controller) {
                      _webViewController = controller;
                    },
                    
                    onLoadStart: (controller, url) {
                      setState(() {
                        _isLoading = true;
                      });
                      if (url != null) {
                        _syncNavigationTab(url.toString());
                      }
                    },
                    
                    onLoadStop: (controller, url) async {
                      _pullToRefreshController?.endRefreshing();
                      
                      // Inject custom CSS styling to strip web elements
                      await controller.insertCSS(source: _appCustomCss);
                      
                      setState(() {
                        _isLoading = false;
                      });
                      
                      if (url != null) {
                        _syncNavigationTab(url.toString());
                      }
                    },
                    
                    onProgressChanged: (controller, progress) {
                      if (progress == 100) {
                        _pullToRefreshController?.endRefreshing();
                      }
                      setState(() {
                        _progress = progress / 100;
                        if (progress == 100) {
                          _isLoading = false;
                        }
                      });
                    },
                    
                    // Handle file downloads (loopkits/graphics/beats)
                    onDownloadStartRequest: (controller, downloadStartRequest) async {
                      final url = downloadStartRequest.url.toString();
                      if (await canLaunchUrl(Uri.parse(url))) {
                        // Redirect download handling to standard mobile browser securely
                        await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
                      }
                    },
                    
                    // Route external URLs (social sites, support, Stripe links)
                    shouldOverrideUrlLoading: (controller, navigationAction) async {
                      final uri = navigationAction.request.url;
                      if (uri != null) {
                        final url = uri.toString();
                        // If navigating away from the base domain (e.g. Stripe checkout, external links)
                        if (!url.startsWith(_baseUrl) && !url.contains('unoeuro.com')) {
                          if (await canLaunchUrl(uri)) {
                            await launchUrl(uri, mode: LaunchMode.externalApplication);
                            return NavigationActionPolicy.CANCEL; // Block in-webview loading
                          }
                        }
                      }
                      return NavigationActionPolicy.ALLOW;
                    },
                  ),
                  
                  // LINEAR CRITICAL LOADING INDICATOR (TOP BAR PHOSPHOR GLOW)
                  if (_isLoading)
                    Positioned(
                      top: 0,
                      left: 0,
                      right: 0,
                      child: LinearProgressIndicator(
                        value: _progress > 0 ? _progress : null,
                        color: AppTheme.textMain,
                        backgroundColor: AppTheme.bgDark,
                        minHeight: 3,
                      ),
                    ),
                    
                  // GLOWING NATIVE LOADING SCREEN MODAL OVERLAY
                  if (_isLoading && _progress < 0.3)
                    Positioned.fill(
                      child: Container(
                        color: AppTheme.bgDark.withOpacity(0.9),
                        child: Center(
                          child: Column(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              const CircularProgressIndicator(
                                color: AppTheme.textMain,
                                strokeWidth: 3,
                              ),
                              const SizedBox(height: 16),
                              Text(
                                "LINKING CONSOLE STATE...",
                                style: GoogleFonts.vt323(
                                  color: AppTheme.textMain,
                                  fontSize: 16,
                                  letterSpacing: 1.0,
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                    ),
                ],
              ),
              
        // PREMIUM BOTTOM NAVIGATION
        bottomNavigationBar: Theme(
          data: Theme.of(context).copyWith(
            canvasColor: AppTheme.bgDark,
          ),
          child: Container(
            decoration: const BoxDecoration(
              border: Border(
                top: BorderSide(color: AppTheme.borderGreen, width: 1.5),
              ),
            ),
            child: BottomNavigationBar(
              currentIndex: _currentTab,
              onTap: _onBottomTabTap,
              items: const [
                BottomNavigationBarItem(
                  icon: Icon(Icons.home_outlined),
                  activeIcon: Icon(Icons.home, color: AppTheme.textMain),
                  label: 'HOME',
                ),
                BottomNavigationBarItem(
                  icon: Icon(Icons.music_note_outlined),
                  activeIcon: Icon(Icons.music_note, color: AppTheme.textMain),
                  label: 'SHOP',
                ),
                BottomNavigationBarItem(
                  icon: Icon(Icons.forum_outlined),
                  activeIcon: Icon(Icons.forum, color: AppTheme.textMain),
                  label: 'FORUM',
                ),
                BottomNavigationBarItem(
                  icon: Icon(Icons.person_outline),
                  activeIcon: Icon(Icons.person, color: AppTheme.textMain),
                  label: 'PORTAL',
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
