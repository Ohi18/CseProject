
<?php
session_start();

// If user is already logged in, redirect them straight to the right dashboard
if (isset($_SESSION['user_id']) && isset($_SESSION['user_type'])) {
    if ($_SESSION['user_type'] === 'saloon') {
        header('Location: saloon_dashboard.php');
        exit();
    } elseif ($_SESSION['user_type'] === 'customer') {
        header('Location: customer_dashboard.php');
        exit();
    }
}

// Load saloons from database for public homepage
$saloons = [];

$host = "localhost";
$username = "root";
$password = "";
$database = "goglam";

try {
    $conn = new mysqli($host, $username, $password, $database);
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }

    $sql = "SELECT s.saloon_id,
                   s.name,
                   s.address,
                   s.phone_no,
                   s.email,
                   l.name AS location_name
            FROM saloon s
            LEFT JOIN location l ON s.location_id = l.location_id
            ORDER BY s.name";

    if ($result = $conn->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            $saloons[] = $row;
        }
        $result->free();
    }

    $conn->close();
} catch (Exception $e) {
    // In a public homepage we fail softly – just show no saloons if DB is down
    $saloons = [];
}

// Generate cache-busting version for slideshow images based on file hash
// This ensures URLs change whenever file content changes, regardless of modification time
function getImageVersion($imagePath) {
    $fullPath = __DIR__ . '/' . $imagePath;
    if (file_exists($fullPath)) {
        // Use MD5 hash of file content - this changes whenever the file content changes
        $fileHash = md5_file($fullPath);
        $mtime = filemtime($fullPath);
        $fileSize = filesize($fullPath);
        
        // #region agent log
        $logData = json_encode([
            'location' => 'index.php:getImageVersion',
            'message' => 'Getting image version',
            'data' => [
                'imagePath' => $imagePath,
                'fullPath' => $fullPath,
                'fileExists' => file_exists($fullPath),
                'fileHash' => $fileHash,
                'mtime' => $mtime,
                'fileSize' => $fileSize,
                'currentTime' => time()
            ],
            'timestamp' => round(microtime(true) * 1000),
            'sessionId' => 'debug-session',
            'runId' => 'run3',
            'hypothesisId' => 'C'
        ]);
        file_put_contents(__DIR__ . '/.cursor/debug.log', $logData . "\n", FILE_APPEND);
        // #endregion agent log
        
        // Use full hash for maximum cache-busting - this ensures URLs change whenever content changes
        // Also include file size as additional verification
        return $fileHash . '_' . $fileSize;
    }
    return time(); // Fallback to current time if file doesn't exist
}

// Get page-level timestamp for additional cache-busting
$pageTimestamp = time();

$imageVersions = [
    'hair' => getImageVersion('assets/images/hero/hair-service.jpg'),
    'nail' => getImageVersion('assets/images/hero/nail-service.jpg'),
    'facial' => getImageVersion('assets/images/hero/facial.jpg'),
    'makeover' => getImageVersion('assets/images/hero/makeover.jpg'),
    'body-spa' => getImageVersion('assets/images/hero/body-spa.jpg'),
    'mani-pedi' => getImageVersion('assets/images/hero/manicure-pedicure.jpg'),
    'consultation' => getImageVersion('assets/images/hero/consultation.jpg')
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GoGlam</title>
    <link rel="icon" type="image/png" href="goglam-logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #faf5f7;
            color: #1a1a1a;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .page {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .nav {
            width: 100%;
            padding: 16px 24px;
            display: flex;
            justify-content: center;
        }

        .nav-inner {
            width: 100%;
            max-width: 1100px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            color: #7A1C2C;
        }

        .brand-logo {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            object-fit: cover;
            box-shadow: 0 6px 14px rgba(0, 0, 0, 0.12);
            background: #fff;
        }

        .brand-text {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: 0.04em;
        }

        .nav-links {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .nav-search-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            padding: 0;
            border: 1px solid rgba(122, 28, 44, 0.15);
            border-radius: 8px;
            background: transparent;
            color: #7A1C2C;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .nav-search-btn:hover {
            background: rgba(122, 28, 44, 0.06);
            border-color: rgba(122, 28, 44, 0.25);
        }

        .nav-link {
            text-decoration: none;
            font-size: 14px;
            padding: 8px 16px;
            border-radius: 999px;
            border: 1px solid transparent;
            color: #7A1C2C;
            background: transparent;
            transition: all 0.2s ease;
        }

        .nav-link:hover {
            background: rgba(122, 28, 44, 0.06);
            border-color: rgba(122, 28, 44, 0.15);
        }

        .nav-link.primary {
            background: #7A1C2C;
            color: #ffffff;
            border-color: #7A1C2C;
        }

        .nav-link.primary:hover {
            background: #5a141f;
        }

        .hero {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 40px 24px 32px;
            background: radial-gradient(circle at top, #fbe1ea 0, #faf5f7 45%, #f3e8f5 100%);
        }

        /* Slider hero */
        .hero-slider {
            position: relative;
            width: 100%;
            max-width: 1100px;
            height: 420px;
            border-radius: 32px;
            overflow: hidden;
            box-shadow: 0 26px 70px rgba(0, 0, 0, 0.20);
            background: #f8e5ec;
            margin-bottom: 32px;
        }

        .hero-slides {
            position: relative;
            width: 100%;
            height: 100%;
        }

        .hero-slide {
            position: absolute;
            inset: 0;
            opacity: 0;
            transition: opacity 0.8s ease;
            display: flex;
            align-items: stretch;
        }

        .hero-slide.is-active {
            opacity: 1;
            pointer-events: auto;
        }

        .hero-slide-image {
            flex: 2;
            position: relative;
            overflow: hidden;
        }

        .hero-slide-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
            display: block;
        }


        .hero-slide-content {
            flex: 1.4;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 32px 32px 32px 28px;
            background: linear-gradient(135deg, rgba(250, 245, 247, 0.96), rgba(248, 226, 234, 0.96));
        }

        .hero-slide-tag {
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #b2677a;
            margin-bottom: 10px;
        }

        .hero-slide-title {
            font-size: 26px;
            font-weight: 700;
            margin-bottom: 6px;
            color: #2a1a1f;
        }

        .hero-slide-title span {
            color: #7A1C2C;
        }

        .hero-slide-text {
            font-size: 14px;
            color: #5c4b52;
            line-height: 1.5;
        }

        .hero-logo-badge {
            width: 120px;
            height: 120px;
            border-radius: 32px;
            background: #fff;
            box-shadow: 0 18px 42px rgba(0, 0, 0, 0.18);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
        }

        .hero-logo-badge img {
            max-width: 86%;
            max-height: 86%;
            border-radius: 28px;
        }

        .hero-slider-controls {
            position: absolute;
            inset-inline: 18px;
            bottom: 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            pointer-events: none;
        }

        .hero-arrows {
            display: flex;
            gap: 8px;
            pointer-events: auto;
        }

        .hero-arrow {
            width: 34px;
            height: 34px;
            border-radius: 999px;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.96);
            color: #7A1C2C;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.16);
            cursor: pointer;
            font-size: 16px;
        }

        .hero-arrow:hover {
            background: #7A1C2C;
            color: #ffffff;
        }

        .hero-dots {
            display: flex;
            gap: 6px;
            pointer-events: auto;
        }

        .hero-dot {
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.7);
            border: none;
            padding: 0;
            cursor: pointer;
        }

        .hero-dot.is-active {
            width: 22px;
            background: #7A1C2C;
        }

        .hero-heading {
            font-size: clamp(28px, 4.4vw, 40px);
            font-weight: 700;
            text-align: center;
            margin-bottom: 10px;
        }

        .hero-heading span {
            color: #7A1C2C;
        }

        .hero-subtitle {
            max-width: 520px;
            text-align: center;
            font-size: 15px;
            color: #555;
            margin-bottom: 8px;
        }

        .hero-note {
            font-size: 12px;
            color: #888;
            text-align: center;
        }

        .section-saloons {
            padding: 40px 24px 32px;
            background: #ffffff;
        }

        .section-inner {
            max-width: 1100px;
            margin: 0 auto;
        }

        .section-header {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            margin-bottom: 28px;
        }

        .section-title {
            font-size: 22px;
            font-weight: 600;
        }

        .section-subtitle {
            font-size: 13px;
            color: #777;
            text-align: center;
        }

        .search-wrap {
            width: 100%;
            max-width: 800px;
            position: relative;
        }

        .search-input {
            width: 100%;
            padding: 12px 14px 12px 40px;
            border-radius: 999px;
            border: 1px solid #ddd;
            font-size: 14px;
            font-family: inherit;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s, background-color 0.2s;
            background-color: #fafafa;
        }

        .search-input:focus {
            border-color: #7A1C2C;
            box-shadow: 0 0 0 2px rgba(122, 28, 44, 0.12);
            background-color: #ffffff;
        }

        .search-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 14px;
            pointer-events: none;
            z-index: 1;
        }

        .gps-btn {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            width: 36px;
            height: 36px;
            border: none;
            border-radius: 50%;
            background: transparent;
            color: #7A1C2C;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            z-index: 2;
        }

        .gps-btn:hover {
            background: rgba(122, 28, 44, 0.1);
        }

        .gps-btn:active {
            background: rgba(122, 28, 44, 0.2);
        }

        .gps-btn.loading {
            animation: pulse 1.5s ease-in-out infinite;
        }

        .gps-btn.active {
            background: rgba(122, 28, 44, 0.15);
            color: #5a141f;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
            color: #999;
        }

        .saloons-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 18px;
        }

        .saloon-card {
            background: #fafafa;
            border-radius: 14px;
            padding: 16px 16px 14px;
            border: 1px solid #ececec;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.06);
            display: flex;
            flex-direction: column;
            gap: 6px;
            transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
        }

        .saloon-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 26px rgba(0, 0, 0, 0.10);
            border-color: #e2d0dc;
        }

        .saloon-name {
            font-size: 16px;
            font-weight: 600;
        }

        .saloon-location {
            font-size: 13px;
            color: #7A1C2C;
            font-weight: 500;
        }

        .saloon-distance {
            margin-left: 8px;
            font-size: 12px;
            color: #666;
            font-weight: 400;
        }

        #gpsStatus {
            display: none;
            margin-bottom: 12px;
            padding: 8px 12px;
            background: #f0f0f0;
            border-radius: 6px;
            font-size: 13px;
            color: #666;
        }

        #gpsStatus.loading {
            background: #e3f2fd;
            color: #1976d2;
        }

        #gpsStatus.success {
            background: #e8f5e9;
            color: #2e7d32;
        }

        #gpsStatus.error {
            background: #ffebee;
            color: #c62828;
        }

        .saloon-meta {
            font-size: 12px;
            color: #666;
        }

        .saloon-actions {
            margin-top: 8px;
        }

        .saloon-link {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 12px;
            padding: 7px 12px;
            border-radius: 999px;
            background: #7A1C2C;
            color: #ffffff;
            text-decoration: none;
            font-weight: 500;
        }

        .saloon-link:hover {
            background: #5a141f;
        }

        .empty-state {
            text-align: center;
            color: #777;
            font-size: 13px;
            padding: 18px 8px;
        }

        .site-footer {
            border-top: 1px solid #eee;
            padding: 16px 24px 20px;
            background: #faf5f7;
            font-size: 12px;
            color: #777;
        }

        .site-footer-inner {
            max-width: 1100px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            align-items: center;
        }

        .footer-links {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
        }

        .footer-link {
            text-decoration: none;
            color: #7A1C2C;
            position: relative;
            cursor: pointer;
        }

        .footer-link:hover,
        .footer-link:focus {
            text-decoration: underline;
        }

        .footer-link[data-tooltip]::before {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            margin-bottom: 8px;
            padding: 12px 16px;
            background: #7A1C2C;
            color: #ffffff;
            font-size: 12px;
            line-height: 1.5;
            border-radius: 4px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: opacity 0.2s ease, visibility 0.2s ease;
            z-index: 1000;
            max-width: 320px;
            min-width: 200px;
            white-space: normal;
            text-align: left;
        }

        .footer-link[data-tooltip]::after {
            content: '';
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            margin-bottom: 2px;
            border: 6px solid transparent;
            border-top-color: #7A1C2C;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: opacity 0.2s ease, visibility 0.2s ease;
            z-index: 1001;
        }

        .footer-link[data-tooltip]:hover::before,
        .footer-link[data-tooltip]:focus::before,
        .footer-link[data-tooltip]:hover::after,
        .footer-link[data-tooltip]:focus::after {
            opacity: 1;
            visibility: visible;
        }

        @media (max-width: 900px) {
            .hero-slider {
                height: 360px;
            }

            .hero-slide-content {
                padding: 24px 22px;
            }

            .hero-slide-title {
                font-size: 22px;
            }
        }

        @media (max-width: 640px) {
            .nav-inner {
                flex-direction: row;
            }

            .hero {
                padding-top: 28px;
                padding-bottom: 26px;
            }

            .hero-slider {
                height: 320px;
                border-radius: 24px;
            }

            .hero-slide {
                flex-direction: column;
            }

            .hero-slide-image {
                flex: 1.2;
            }

            .hero-slide-content {
                flex: 1;
                padding: 18px 18px 20px;
            }

            .hero-slider-controls {
                inset-inline: 14px;
                bottom: 12px;
            }

            .hero-arrow {
                width: 30px;
                height: 30px;
            }

            .site-footer-inner {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <header class="nav">
            <div class="nav-inner">
                <a href="index.php" class="brand">
                    <img src="goglam-logo.png" alt="GoGlam Logo" class="brand-logo">
                    <span class="brand-text">GoGlam</span>
                </a>
                <div class="nav-links">
                    <button class="nav-search-btn" type="button" aria-label="Search" onclick="document.getElementById('homeSearchInput').focus();">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M7.333 12.667A5.333 5.333 0 1 0 7.333 2a5.333 5.333 0 0 0 0 10.667ZM14 14l-2.9-2.9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                    <a class="nav-link" href="login.php">Login</a>
                    <a class="nav-link primary" href="registration.php">Register</a>
                </div>
            </div>
        </header>

        <main>
            <!-- Hero with slideshow -->
            <section class="hero">
                <div class="hero-slider" id="heroSlider" aria-label="GoGlam services slideshow">
                    <div class="hero-slides">
                        <!-- Slide 1: GoGlam logo -->
                        <article class="hero-slide is-active" data-index="0">
                            <div class="hero-slide-image">
                                <img src="goglam-logo.png" alt="GoGlam logo" class="hero-slide-img">
                            </div>
                            <div class="hero-slide-content">
                                <div class="hero-slide-tag">Welcome to GoGlam</div>
                                <h2 class="hero-slide-title"><span>GoGlam</span> at your fingertips</h2>
                                <p class="hero-slide-text">
                                    Your glam-up is one tap away. Discover trusted saloons, compare services and locations, and book in seconds.
                                </p>
                            </div>
                        </article>

                        <!-- Slide 2: Hair Service -->
                        <article class="hero-slide" data-index="1">
                            <div class="hero-slide-image">
                                <img src="haircut.jpg" alt="Hair Service" class="hero-slide-img">
                            </div>
                            <div class="hero-slide-content">
                                <div class="hero-slide-tag">Signature service</div>
                                <h2 class="hero-slide-title"><span>Hair</span> Service</h2>
                                <p class="hero-slide-text">
                                    Cuts, color, styling and treatments from salons that understand your hair type and desired look.
                                </p>
                            </div>
                        </article>

                        <!-- Slide 3: Nail Service -->
                        <article class="hero-slide" data-index="2">
                            <div class="hero-slide-image">
                                <img src="nail3.jpg" alt="Nail Service" class="hero-slide-img">
                            </div>
                            <div class="hero-slide-content">
                                <div class="hero-slide-tag">Detail obsessed</div>
                                <h2 class="hero-slide-title"><span>Nail</span> Service</h2>
                                <p class="hero-slide-text">
                                    From classic manicures to bold nail art, find experts who make every detail picture-perfect.
                                </p>
                            </div>
                        </article>

                        <!-- Slide 4: Facial -->
                        <article class="hero-slide" data-index="5">
                            <div class="hero-slide-image">
                                <img src="face.jpg" alt="Facial Care" class="hero-slide-img">
                            </div>
                            <div class="hero-slide-content">
                                <div class="hero-slide-tag">Glow care</div>
                                <h2 class="hero-slide-title"><span>Facial</span> Care</h2>
                                <p class="hero-slide-text">
                                    Targeted facials for glow, hydration and clarity, matched to your skin needs.
                                </p>
                            </div>
                        </article>

                        <!-- Slide 5: Makeover -->
                        <article class="hero-slide" data-index="6">
                            <div class="hero-slide-image">
                                <img src="makeup.jpg" alt="Makeover" class="hero-slide-img">
                            </div>
                            <div class="hero-slide-content">
                                <div class="hero-slide-tag">Big moments</div>
                                <h2 class="hero-slide-title"><span>Makeover</span></h2>
                                <p class="hero-slide-text">
                                    From parties to weddings, book artists who create looks that photograph beautifully and last.
                                </p>
                            </div>
                        </article>

                        <!-- Slide 6: Body Spa -->
                        <article class="hero-slide" data-index="7">
                            <div class="hero-slide-image">
                                <img src="body.jpg" alt="Body Spa" class="hero-slide-img">
                            </div>
                            <div class="hero-slide-content">
                                <div class="hero-slide-tag">Deep relaxation</div>
                                <h2 class="hero-slide-title"><span>Body</span> Spa</h2>
                                <p class="hero-slide-text">
                                    Unwind with massages and spa rituals that melt away stress and leave your body feeling lighter.
                                </p>
                            </div>
                        </article>

                        <!-- Slide 7: Pedicure / Manicure -->
                        <article class="hero-slide" data-index="8">
                            <div class="hero-slide-image">
                                <img src="pm.jpg" alt="Pedicure Manicure" class="hero-slide-img">
                            </div>
                            <div class="hero-slide-content">
                                <div class="hero-slide-tag">Relax & renew</div>
                                <h2 class="hero-slide-title"><span>Pedicure</span> / Manicure</h2>
                                <p class="hero-slide-text">
                                    Give your hands and feet the care they deserve with hygienic, relaxing mani-pedi sessions.
                                </p>
                            </div>
                        </article>

                        <!-- Slide 8: Beauty Consultation -->
                        <article class="hero-slide" data-index="9">
                            <div class="hero-slide-image">
                                <img src="con.jpg" alt="Beauty Consultation" class="hero-slide-img">
                            </div>
                            <div class="hero-slide-content">
                                <div class="hero-slide-tag">Personal guidance</div>
                                <h2 class="hero-slide-title"><span>Beauty</span> Consultation</h2>
                                <p class="hero-slide-text">
                                    Not sure what you need? Book a quick consultation and let experts guide your next beauty session.
                                </p>
                            </div>
                        </article>

                    </div>

                    <div class="hero-slider-controls">
                        <div class="hero-arrows">
                            <button class="hero-arrow" type="button" aria-label="Previous slide" data-hero-prev>&lsaquo;</button>
                            <button class="hero-arrow" type="button" aria-label="Next slide" data-hero-next>&rsaquo;</button>
                        </div>
                        <div class="hero-dots" aria-label="Slide indicators">
                            <button class="hero-dot is-active" type="button" data-hero-dot="0"></button>
                            <button class="hero-dot" type="button" data-hero-dot="1"></button>
                            <button class="hero-dot" type="button" data-hero-dot="2"></button>
                            <button class="hero-dot" type="button" data-hero-dot="5"></button>
                            <button class="hero-dot" type="button" data-hero-dot="6"></button>
                            <button class="hero-dot" type="button" data-hero-dot="7"></button>
                            <button class="hero-dot" type="button" data-hero-dot="8"></button>
                            <button class="hero-dot" type="button" data-hero-dot="9"></button>
                        </div>
                    </div>
                </div>

                <h1 class="hero-heading">
                    <span>GoGlam</span> connects customers and saloons.
                </h1>
                <p class="hero-subtitle">
                    Scroll down to explore trusted saloons, compare services and locations, and start your next beauty session.
                </p>
                <p class="hero-note">
                    Already have an account? Use Login or Register at the top right to manage your bookings.
                </p>
            </section>

            <!-- Saloons section with search -->
            <section class="section-saloons" id="saloons">
                <div class="section-inner">
                    <div class="section-header">
                        <h2 class="section-title">Discover saloons on GoGlam</h2>
                        <p class="section-subtitle">Search by saloon name or location. Results update instantly as you type.</p>
                        <div id="gpsStatus" style="display: none; margin-bottom: 12px; padding: 8px 12px; background: #f0f0f0; border-radius: 6px; font-size: 13px; color: #666;"></div>
                        <div class="search-wrap">
                            <span class="search-icon">&#128269;</span>
                            <input
                                type="text"
                                id="homeSearchInput"
                                class="search-input"
                                placeholder="Search saloons by name or location..."
                                autocomplete="off"
                            >
                            <button type="button" class="gps-btn" id="gpsBtn" aria-label="Find nearby saloons using GPS" title="Find nearby saloons">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z" fill="currentColor"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <?php if (empty($saloons)): ?>
                        <div class="empty-state" id="noSaloonsMessage">
                            No saloons are available right now. Please check back later.
                        </div>
                    <?php else: ?>
                        <div class="saloons-grid" id="saloonsGrid">
                            <?php foreach ($saloons as $saloon): ?>
                                <?php
                                    $name = $saloon['name'] ?? '';
                                    $locationName = $saloon['location_name'] ?? '';
                                    $address = $saloon['address'] ?? '';
                                    $phone = $saloon['phone_no'] ?? '';
                                    $email = $saloon['email'] ?? '';
                                ?>
                                <article
                                    class="saloon-card"
                                    data-name="<?php echo htmlspecialchars(strtolower($name)); ?>"
                                    data-location="<?php echo htmlspecialchars(strtolower($locationName)); ?>"
                                    data-address="<?php echo htmlspecialchars($address); ?>"
                                    data-distance=""
                                >
                                    <div class="saloon-name"><?php echo htmlspecialchars($name); ?></div>
                                    <?php if (!empty($locationName)): ?>
                                        <div class="saloon-location">
                                            <?php echo htmlspecialchars($locationName); ?>
                                            <span class="saloon-distance" style="display: none;"></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($address)): ?>
                                        <div class="saloon-meta"><?php echo htmlspecialchars($address); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($phone) || !empty($email)): ?>
                                        <div class="saloon-meta">
                                            <?php if (!empty($phone)): ?>
                                                Phone: <?php echo htmlspecialchars($phone); ?>
                                            <?php endif; ?>
                                            <?php if (!empty($phone) && !empty($email)): ?>
                                                &nbsp;•&nbsp;
                                            <?php endif; ?>
                                            <?php if (!empty($email)): ?>
                                                Email: <?php echo htmlspecialchars($email); ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="saloon-actions">
                                        <a
                                            class="saloon-link"
                                            href="customer_dashboard.php?saloon_id=<?php echo (int)$saloon['saloon_id']; ?>"
                                        >
                                            View details &amp; book
                                        </a>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                        <div class="empty-state" id="noSearchResultsMessage" style="display: none;">
                            No saloons match your search. Try a different name or location.
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </main>

        <footer class="site-footer">
            <div class="site-footer-inner">
                <div>&copy; <?php echo date('Y'); ?> GoGlam. All rights reserved.</div>
                <div class="footer-links">
                    <a href="#" class="footer-link" data-tooltip="GoGlam is a smart beauty discovery platform that helps you find the nearest trusted salons with ease. We connect customers and salon owners through a seamless, modern experience—making beauty services simple, transparent, and accessible.">About Us</a>
                    <a href="#" class="footer-link" data-tooltip="Need assistance using GoGlam? We're here to help. Easily explore nearby salons, view services and prices, and manage your bookings with confidence. If you face any issues or have questions, our support is always ready to guide you.">Help</a>
                    <a href="mailto:support@goglam.local" class="footer-link" data-tooltip="Contact us at support@goglam.local for assistance and inquiries.">Email</a>
                </div>
            </div>
        </footer>
    </div>

    <script>
        (function() {
            const searchInput = document.getElementById('homeSearchInput');
            const grid = document.getElementById('saloonsGrid');
            const noResults = document.getElementById('noSearchResultsMessage');
            const noSaloonsMessage = document.getElementById('noSaloonsMessage');

            if (searchInput && grid) {
                const cards = Array.prototype.slice.call(grid.getElementsByClassName('saloon-card'));

                function applyFilter() {
                    const term = searchInput.value.trim().toLowerCase();
                    const maxDistanceKm = 30; // Maximum distance in kilometers

                    // If GPS is active and we have a location, filter by distance
                    if (isGpsActive && userLocation) {
                        let visibleCount = 0;
                        cards.forEach(function(card) {
                            const distance = parseFloat(card.getAttribute('data-distance')) || Infinity;
                            
                            // Show card if it's within 30km
                            if (distance <= maxDistanceKm) {
                                card.style.display = '';
                                visibleCount++;
                            } else {
                                card.style.display = 'none';
                            }
                        });

                        // Sort by distance
                        sortByDistance();

                        if (noResults) {
                            noResults.style.display = visibleCount === 0 ? '' : 'none';
                        }
                        if (noSaloonsMessage) {
                            noSaloonsMessage.style.display = 'none';
                        }
                        return;
                    }

                    // Normal text-based filtering when GPS is not active
                    if (!term) {
                        cards.forEach(function(card) {
                            card.style.display = '';
                        });
                        if (noResults) noResults.style.display = 'none';
                        if (noSaloonsMessage) noSaloonsMessage.style.display = cards.length ? 'none' : '';
                        return;
                    }

                    let visibleCount = 0;
                    cards.forEach(function(card) {
                        const name = card.getAttribute('data-name') || '';
                        const location = card.getAttribute('data-location') || '';
                        const text = name + ' ' + location;

                        if (text.indexOf(term) !== -1) {
                            card.style.display = '';
                            visibleCount++;
                        } else {
                            card.style.display = 'none';
                        }
                    });

                    if (noResults) {
                        noResults.style.display = visibleCount === 0 ? '' : 'none';
                    }
                    if (noSaloonsMessage) {
                        noSaloonsMessage.style.display = 'none';
                    }
                }

                searchInput.addEventListener('input', applyFilter);
            }

            // GPS Location Search Functionality
            console.log('Initializing GPS functionality...');
            const gpsBtn = document.getElementById('gpsBtn');
            console.log('GPS button lookup result:', gpsBtn);
            console.log('GPS button exists:', !!gpsBtn);
            
            if (!gpsBtn) {
                console.error('CRITICAL: GPS button not found! Make sure the button has id="gpsBtn"');
            }
            
            let userLocation = null;
            let isGpsActive = false;
            let watchId = null; // Store watchPosition ID for cleanup
            let isRequestingLocation = false; // Prevent multiple simultaneous requests
            let locationRequestTimeout = null; // Timeout to reset stuck state
            let locationRefreshInterval = null; // Interval for periodic location updates
            const geocodeCache = JSON.parse(sessionStorage.getItem('geocodeCache') || '{}');

            // Haversine formula to calculate distance between two coordinates
            function calculateDistance(lat1, lon1, lat2, lon2) {
                const R = 6371; // Earth's radius in km
                const dLat = (lat2 - lat1) * Math.PI / 180;
                const dLon = (lon2 - lon1) * Math.PI / 180;
                const a = 
                    Math.sin(dLat/2) * Math.sin(dLat/2) +
                    Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                    Math.sin(dLon/2) * Math.sin(dLon/2);
                const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
                return R * c; // Distance in km
            }

            // Format distance for display
            function formatDistance(km) {
                if (km < 1) {
                    return Math.round(km * 1000) + ' m away';
                }
                return km.toFixed(1) + ' km away';
            }

            // Geocode address using Photon (Komoot) API
            async function geocodeAddress(address) {
                console.log('Geocoding address:', address);
                // Check cache first
                if (geocodeCache[address]) {
                    console.log('Using cached coordinates for:', address, geocodeCache[address]);
                    return geocodeCache[address];
                }

                try {
                    const encodedAddress = encodeURIComponent(address);
                    const url = `https://photon.komoot.io/api/?q=${encodedAddress}&limit=1`;
                    console.log('Fetching geocode from:', url);
                    
                    const response = await fetch(url);
                    
                    if (!response.ok) {
                        console.error('Geocoding API response not OK:', response.status, response.statusText);
                        throw new Error('Geocoding failed');
                    }
                    
                    const data = await response.json();
                    console.log('Geocoding response:', data);
                    
                    // Photon returns GeoJSON format with features array
                    if (data && data.features && data.features.length > 0) {
                        const feature = data.features[0];
                        const coords = {
                            lat: parseFloat(feature.geometry.coordinates[1]), // Photon uses [lon, lat] format
                            lon: parseFloat(feature.geometry.coordinates[0])
                        };
                        console.log('Geocoded coordinates:', coords);
                        // Cache the result
                        geocodeCache[address] = coords;
                        sessionStorage.setItem('geocodeCache', JSON.stringify(geocodeCache));
                        return coords;
                    } else {
                        console.warn('No geocoding results for address:', address);
                    }
                } catch (error) {
                    console.error('Geocoding error for address', address, ':', error);
                }
                return null;
            }

            // Helper function to construct address from address components
            function constructAddressFromComponents(addressObj) {
                if (!addressObj) return null;
                
                const parts = [];
                
                // Try to build a readable address in order of preference
                if (addressObj.road || addressObj.street) {
                    parts.push(addressObj.road || addressObj.street);
                }
                if (addressObj.house_number) {
                    parts.unshift(addressObj.house_number); // Put house number first
                }
                if (addressObj.suburb || addressObj.neighbourhood) {
                    parts.push(addressObj.suburb || addressObj.neighbourhood);
                }
                if (addressObj.city || addressObj.town || addressObj.village) {
                    parts.push(addressObj.city || addressObj.town || addressObj.village);
                }
                if (addressObj.state || addressObj.region) {
                    parts.push(addressObj.state || addressObj.region);
                }
                if (addressObj.country) {
                    parts.push(addressObj.country);
                }
                
                return parts.length > 0 ? parts.join(', ') : null;
            }

            // Reverse geocode coordinates to get address using Photon (Komoot) API
            async function reverseGeocode(lat, lon, retryCount = 0) {
                const maxRetries = 2;
                
                console.log('Reverse geocoding coordinates:', lat, lon, 'attempt:', retryCount + 1);
                
                // Create cache key for coordinates
                const cacheKey = `${lat.toFixed(4)},${lon.toFixed(4)}`;
                const reverseGeocodeCache = JSON.parse(sessionStorage.getItem('reverseGeocodeCache') || '{}');
                
                // Check cache first - but only if it's a valid address (not null/empty)
                if (reverseGeocodeCache[cacheKey] && reverseGeocodeCache[cacheKey].trim() !== '') {
                    console.log('Using cached address for coordinates:', cacheKey, reverseGeocodeCache[cacheKey]);
                    return reverseGeocodeCache[cacheKey];
                } else if (reverseGeocodeCache[cacheKey] === null || reverseGeocodeCache[cacheKey] === '') {
                    // Clear invalid cache entry
                    delete reverseGeocodeCache[cacheKey];
                    sessionStorage.setItem('reverseGeocodeCache', JSON.stringify(reverseGeocodeCache));
                    console.log('Cleared invalid cache entry for:', cacheKey);
                }

                // Small delay for retries (Photon is more lenient with rate limits)
                if (retryCount > 0) {
                    const delay = 1000 * retryCount; // 1 second per retry
                    console.log(`Waiting ${delay}ms before retry...`);
                    await new Promise(resolve => setTimeout(resolve, delay));
                }

                try {
                    const url = `https://photon.komoot.io/reverse?lat=${lat}&lon=${lon}`;
                    console.log('Fetching reverse geocode from:', url);
                    
                    // Create AbortController for timeout handling
                    const controller = new AbortController();
                    const timeoutId = setTimeout(() => controller.abort(), 10000); // 10 second timeout
                    
                    const response = await fetch(url, {
                        signal: controller.signal
                    });
                    
                    clearTimeout(timeoutId);
                    
                    if (!response.ok) {
                        console.error('Reverse geocoding API response not OK:', {
                            status: response.status,
                            statusText: response.statusText,
                            url: url
                        });
                        
                        // Retry if we have retries left
                        if (retryCount < maxRetries) {
                            console.log(`Retrying reverse geocoding (${retryCount + 1}/${maxRetries})...`);
                            return await reverseGeocode(lat, lon, retryCount + 1);
                        }
                        
                        throw new Error(`Reverse geocoding failed: ${response.status} ${response.statusText}`);
                    }
                    
                    const data = await response.json();
                    console.log('Reverse geocoding response:', JSON.stringify(data, null, 2));
                    
                    // Photon returns GeoJSON format
                    if (data && data.features && data.features.length > 0) {
                        const feature = data.features[0];
                        const properties = feature.properties || {};
                        
                        // Construct address from Photon properties
                        const addressParts = [];
                        
                        // Photon uses different property names
                        if (properties.housenumber) addressParts.push(properties.housenumber);
                        if (properties.street) addressParts.push(properties.street);
                        if (properties.city || properties.locality) addressParts.push(properties.city || properties.locality);
                        if (properties.state || properties.region) addressParts.push(properties.state || properties.region);
                        if (properties.country) addressParts.push(properties.country);
                        
                        // Fallback: use name if available
                        if (addressParts.length === 0 && properties.name) {
                            addressParts.push(properties.name);
                        }
                        
                        const address = addressParts.length > 0 ? addressParts.join(', ') : null;
                        
                        if (address && address.trim() !== '') {
                            // Cache the result only if it's valid
                            reverseGeocodeCache[cacheKey] = address;
                            sessionStorage.setItem('reverseGeocodeCache', JSON.stringify(reverseGeocodeCache));
                            console.log('Address cached successfully:', address);
                            return address;
                        } else {
                            console.warn('No valid address found in reverse geocoding results. Full response:', JSON.stringify(data, null, 2));
                            // Retry if we have retries left
                            if (retryCount < maxRetries) {
                                console.log('Retrying (attempt', retryCount + 2, 'of', maxRetries + 1, ')...');
                                return await reverseGeocode(lat, lon, retryCount + 1);
                            } else {
                                console.error('All retry attempts exhausted. Could not get address.');
                            }
                        }
                    } else {
                        console.warn('No features in reverse geocoding response. Full response:', JSON.stringify(data, null, 2));
                        // Retry if we have retries left
                        if (retryCount < maxRetries) {
                            console.log('Retrying (attempt', retryCount + 2, 'of', maxRetries + 1, ')...');
                            return await reverseGeocode(lat, lon, retryCount + 1);
                        } else {
                            console.error('All retry attempts exhausted. Could not get address.');
                        }
                    }
                } catch (error) {
                    console.error('Reverse geocoding error for coordinates', lat, lon, ':', {
                        error: error.message,
                        name: error.name,
                        stack: error.stack
                    });
                    
                    // Retry on network errors if we have retries left
                    if (retryCount < maxRetries && (error.name === 'TypeError' || error.name === 'AbortError')) {
                        console.log(`Retrying reverse geocoding after error (${retryCount + 1}/${maxRetries})...`);
                        return await reverseGeocode(lat, lon, retryCount + 1);
                    }
                }
                return null;
            }

            // Stop watching location
            function stopWatchingLocation() {
                try {
                    if (watchId !== null && navigator.geolocation) {
                        navigator.geolocation.clearWatch(watchId);
                        watchId = null;
                        console.log('Stopped watching location');
                    }
                    // Clear refresh interval
                    if (locationRefreshInterval) {
                        clearInterval(locationRefreshInterval);
                        locationRefreshInterval = null;
                        console.log('Cleared location refresh interval');
                    }
                    // Clear safety timeout
                    if (locationRequestTimeout) {
                        clearTimeout(locationRequestTimeout);
                        locationRequestTimeout = null;
                    }
                } catch (error) {
                    console.error('Error stopping location watch:', error);
                    watchId = null; // Reset even if clearWatch fails
                    if (locationRefreshInterval) {
                        clearInterval(locationRefreshInterval);
                        locationRefreshInterval = null;
                    }
                    if (locationRequestTimeout) {
                        clearTimeout(locationRequestTimeout);
                        locationRequestTimeout = null;
                    }
                }
            }

            // Get user location with continuous watching
            function getUserLocation() {
                try {
                    console.log('getUserLocation() called');
                    console.log('navigator.geolocation exists:', !!navigator.geolocation);
                    console.log('Current GPS state:', { isGpsActive, isRequestingLocation, watchId });
                    
                    // If GPS is already active, don't start a new request
                    if (isGpsActive && userLocation) {
                        console.log('GPS is already active, skipping new request');
                        return;
                    }
                    
                    // Prevent multiple simultaneous requests
                    if (isRequestingLocation) {
                        console.log('Location request already in progress, skipping...');
                        return;
                    }
                    
                    const statusEl = document.getElementById('gpsStatus');
                    
                    if (!navigator.geolocation) {
                        const errorMsg = 'Geolocation is not supported by your browser. Please use a modern browser like Chrome, Firefox, or Edge.';
                        console.error(errorMsg);
                        if (statusEl) {
                            statusEl.textContent = '❌ ' + errorMsg;
                            statusEl.className = 'error';
                            statusEl.style.display = 'block';
                        }
                        alert(errorMsg);
                        return;
                    }

                    // Stop any existing watch and reset state
                    stopWatchingLocation();
                    isGpsActive = false;
                    userLocation = null;

                    console.log('Requesting location with options:', {
                        enableHighAccuracy: true,
                        timeout: 30000,
                        maximumAge: 0
                    });

                    if (statusEl) {
                        statusEl.textContent = '📍 Requesting your location...';
                        statusEl.className = 'loading';
                        statusEl.style.display = 'block';
                    }

                    if (gpsBtn) {
                        gpsBtn.classList.add('loading');
                        gpsBtn.disabled = true;
                        console.log('GPS button set to loading state');
                    }

                    isRequestingLocation = true;

                    // Safety timeout: reset flag if request takes too long (35 seconds)
                    if (locationRequestTimeout) {
                        clearTimeout(locationRequestTimeout);
                    }
                    locationRequestTimeout = setTimeout(() => {
                        console.warn('Location request timeout safety - resetting state');
                        isRequestingLocation = false;
                        if (gpsBtn) {
                            gpsBtn.classList.remove('loading');
                            gpsBtn.disabled = false;
                        }
                        const statusEl = document.getElementById('gpsStatus');
                        if (statusEl) {
                            statusEl.textContent = '⏱️ Location request taking too long. Please try again.';
                            statusEl.className = 'error';
                        }
                    }, 35000); // 35 seconds (5 seconds after geolocation timeout)

                    // First, get current position quickly
                    navigator.geolocation.getCurrentPosition(
                    async (position) => {
                        // Clear safety timeout
                        if (locationRequestTimeout) {
                            clearTimeout(locationRequestTimeout);
                            locationRequestTimeout = null;
                        }
                        
                        isRequestingLocation = false;
                        console.log('Location obtained successfully!', {
                            latitude: position.coords.latitude,
                            longitude: position.coords.longitude,
                            accuracy: position.coords.accuracy
                        });
                        
                        userLocation = {
                            lat: position.coords.latitude,
                            lon: position.coords.longitude
                        };
                        isGpsActive = true;
                        
                        const statusEl = document.getElementById('gpsStatus');
                        if (statusEl) {
                            statusEl.textContent = `✅ Location found! (${position.coords.latitude.toFixed(4)}, ${position.coords.longitude.toFixed(4)}) - Calculating distances...`;
                            statusEl.className = 'success';
                            statusEl.style.display = 'block';
                        }
                        
                        if (gpsBtn) {
                            gpsBtn.classList.remove('loading');
                            gpsBtn.classList.add('active');
                            gpsBtn.disabled = false;
                        }

                        console.log('Processing salons with distance...');
                        await processSalonsWithDistance();
                        console.log('Distance processing completed');
                        
                        if (statusEl) {
                            statusEl.textContent = '✅ Location active - Showing saloons within 30km, sorted by distance';
                            statusEl.className = 'success';
                        }

                        // Use periodic refresh instead of watchPosition for better reliability
                        // Clear any existing interval
                        if (locationRefreshInterval) {
                            clearInterval(locationRefreshInterval);
                        }
                        
                        // Refresh location every 2 minutes if GPS is still active
                        locationRefreshInterval = setInterval(() => {
                            if (isGpsActive && !isRequestingLocation && navigator.geolocation) {
                                console.log('Refreshing location (periodic update)...');
                                navigator.geolocation.getCurrentPosition(
                                    (updatedPosition) => {
                                        if (isGpsActive) {
                                            const distance = calculateDistance(
                                                userLocation.lat, userLocation.lon,
                                                updatedPosition.coords.latitude, updatedPosition.coords.longitude
                                            );
                                            
                                            if (distance > 0.1) { // More than 100 meters
                                                console.log('Location changed significantly:', distance, 'km');
                                                userLocation = {
                                                    lat: updatedPosition.coords.latitude,
                                                    lon: updatedPosition.coords.longitude
                                                };
                                                processSalonsWithDistance();
                                            }
                                        }
                                    },
                                    (error) => {
                                        console.warn('Periodic location refresh failed:', error.message);
                                        // Don't stop GPS on periodic refresh failures
                                    },
                                    {
                                        enableHighAccuracy: false,
                                        timeout: 10000,
                                        maximumAge: 120000 // Accept cached location up to 2 minutes old
                                    }
                                );
                            } else {
                                // GPS deactivated, clear interval
                                if (locationRefreshInterval) {
                                    clearInterval(locationRefreshInterval);
                                    locationRefreshInterval = null;
                                }
                            }
                        }, 120000); // Refresh every 2 minutes

                        // Also use watchPosition for immediate updates (but with better error handling)
                        if (isGpsActive) {
                            watchId = navigator.geolocation.watchPosition(
                                async (updatedPosition) => {
                                    try {
                                        // Check if GPS is still active before processing update
                                        if (!isGpsActive) {
                                            console.log('GPS deactivated, ignoring location update');
                                            return;
                                        }
                                        
                                        console.log('Location updated:', {
                                            latitude: updatedPosition.coords.latitude,
                                            longitude: updatedPosition.coords.longitude,
                                            accuracy: updatedPosition.coords.accuracy
                                        });
                                        
                                        // Only update if position changed significantly (more than 100 meters)
                                        if (userLocation) {
                                            const distance = calculateDistance(
                                                userLocation.lat, userLocation.lon,
                                                updatedPosition.coords.latitude, updatedPosition.coords.longitude
                                            );
                                            
                                            if (distance > 0.1) { // More than 100 meters
                                                console.log('Significant location change detected:', distance, 'km');
                                                userLocation = {
                                                    lat: updatedPosition.coords.latitude,
                                                    lon: updatedPosition.coords.longitude
                                                };
                                                
                                                // Recalculate distances
                                                await processSalonsWithDistance();
                                                
                                                const statusEl = document.getElementById('gpsStatus');
                                                if (statusEl && isGpsActive) {
                                                    statusEl.textContent = `✅ Location updated - Showing saloons within 30km`;
                                                    statusEl.className = 'success';
                                                }
                                            }
                                        } else {
                                            userLocation = {
                                                lat: updatedPosition.coords.latitude,
                                                lon: updatedPosition.coords.longitude
                                            };
                                            await processSalonsWithDistance();
                                        }
                                    } catch (error) {
                                        console.error('Error processing location update:', error);
                                    }
                                },
                                (error) => {
                                    console.warn('Location watch error:', error.message, error.code);
                                    // If watch fails critically, stop watching and reset state
                                    if (error.code === error.PERMISSION_DENIED || error.code === error.POSITION_UNAVAILABLE) {
                                        console.log('Critical watch error, stopping GPS');
                                        stopWatchingLocation();
                                        isGpsActive = false;
                                        userLocation = null;
                                        isRequestingLocation = false;
                                        if (gpsBtn) {
                                            gpsBtn.classList.remove('active');
                                            gpsBtn.classList.remove('loading');
                                            gpsBtn.disabled = false;
                                        }
                                        const statusEl = document.getElementById('gpsStatus');
                                        if (statusEl) {
                                            statusEl.textContent = '❌ GPS tracking stopped due to error';
                                            statusEl.className = 'error';
                                        }
                                    }
                                },
                                {
                                    enableHighAccuracy: true,
                                    timeout: 30000,
                                    maximumAge: 60000
                                }
                            );
                            console.log('Started watching position, watchId:', watchId);
                        }
                    },
                    (error) => {
                        try {
                            // Clear safety timeout
                            if (locationRequestTimeout) {
                                clearTimeout(locationRequestTimeout);
                                locationRequestTimeout = null;
                            }
                            
                            isRequestingLocation = false;
                            console.error('Geolocation error:', {
                                code: error.code,
                                message: error.message,
                                PERMISSION_DENIED: error.PERMISSION_DENIED,
                                POSITION_UNAVAILABLE: error.POSITION_UNAVAILABLE,
                                TIMEOUT: error.TIMEOUT
                            });
                            
                            const statusEl = document.getElementById('gpsStatus');
                            
                            if (gpsBtn) {
                                gpsBtn.classList.remove('loading');
                                gpsBtn.classList.remove('active');
                                gpsBtn.disabled = false;
                            }
                            
                            // Reset state on error
                            isGpsActive = false;
                            userLocation = null;
                            stopWatchingLocation();
                            
                            // Clear refresh interval
                            if (locationRefreshInterval) {
                                clearInterval(locationRefreshInterval);
                                locationRefreshInterval = null;
                            }
                        } catch (err) {
                            console.error('Error in geolocation error handler:', err);
                            // Force reset on handler error
                            isRequestingLocation = false;
                            if (locationRequestTimeout) {
                                clearTimeout(locationRequestTimeout);
                                locationRequestTimeout = null;
                            }
                        }
                        let errorMsg = 'Unable to get your location. ';
                        let canRetry = false;
                        let troubleshooting = '';
                        let statusText = '';
                        
                        switch(error.code) {
                            case error.PERMISSION_DENIED:
                                errorMsg += 'Location permission denied.';
                                statusText = '❌ Location permission denied. Please allow location access in browser settings.';
                                troubleshooting = '\n\nTroubleshooting:\n1. Click the lock/info icon in your browser address bar\n2. Allow location access\n3. Refresh the page and try again';
                                break;
                            case error.POSITION_UNAVAILABLE:
                                errorMsg += 'Location information unavailable.';
                                statusText = '❌ Location unavailable. Check device location settings.';
                                troubleshooting = '\n\nTroubleshooting:\n1. Check if location services are enabled on your device\n2. Make sure you have internet connection\n3. Try refreshing the page';
                                canRetry = true;
                                break;
                            case error.TIMEOUT:
                                errorMsg += 'Location request timed out.';
                                statusText = '⏱️ Location request timed out. Please try again.';
                                troubleshooting = '\n\nTroubleshooting:\n1. Check your internet connection\n2. Make sure location services are enabled\n3. Try again in a few seconds';
                                canRetry = true;
                                break;
                            default:
                                errorMsg += 'An unknown error occurred.';
                                statusText = '❌ Unknown error occurred. Check console for details.';
                                troubleshooting = '\n\nTroubleshooting:\n1. Refresh the page\n2. Check browser console for errors\n3. Try a different browser';
                                canRetry = true;
                                break;
                        }
                        
                        if (statusEl) {
                            statusEl.textContent = statusText;
                            statusEl.className = 'error';
                            statusEl.style.display = 'block';
                        }
                        
                        const fullMessage = errorMsg + troubleshooting;
                        console.error('Full error message:', fullMessage);
                        
                        if (canRetry && confirm(fullMessage + '\n\nWould you like to try again?')) {
                            console.log('User chose to retry');
                            getUserLocation();
                        } else if (!canRetry) {
                            alert(fullMessage);
                        } else {
                            console.log('User cancelled retry');
                        }
                    },
                    {
                        enableHighAccuracy: false,
                        timeout: 20000,
                        maximumAge: 60000
                    }
                );
                } catch (error) {
                    console.error('Error in getUserLocation function:', error);
                    isRequestingLocation = false;
                    if (gpsBtn) {
                        gpsBtn.classList.remove('loading');
                        gpsBtn.classList.remove('active');
                        gpsBtn.disabled = false;
                    }
                    const statusEl = document.getElementById('gpsStatus');
                    if (statusEl) {
                        statusEl.textContent = '❌ An error occurred. Please try again.';
                        statusEl.className = 'error';
                        statusEl.style.display = 'block';
                    }
                }
            }

            // Process all salons and calculate distances
            async function processSalonsWithDistance() {
                console.log('processSalonsWithDistance() called, userLocation:', userLocation);
                if (!userLocation) {
                    console.error('No user location available');
                    return;
                }
                const grid = document.getElementById('saloonsGrid');
                if (!grid) {
                    console.error('Salons grid not found');
                    return;
                }

                const cards = Array.prototype.slice.call(grid.getElementsByClassName('saloon-card'));
                console.log('Found', cards.length, 'salon cards to process');
                const distancePromises = [];

                for (const card of cards) {
                    const address = card.getAttribute('data-address');
                    if (!address) {
                        console.warn('Card has no address attribute:', card);
                        continue;
                    }

                    distancePromises.push(
                        geocodeAddress(address).then(coords => {
                            if (coords) {
                                const distance = calculateDistance(
                                    userLocation.lat,
                                    userLocation.lon,
                                    coords.lat,
                                    coords.lon
                                );
                                console.log('Distance calculated for', address, ':', distance, 'km');
                                card.setAttribute('data-distance', distance);
                                
                                // Show distance in UI
                                const distanceSpan = card.querySelector('.saloon-distance');
                                if (distanceSpan) {
                                    distanceSpan.textContent = formatDistance(distance);
                                    distanceSpan.style.display = 'inline';
                                } else {
                                    console.warn('Distance span not found for card:', card);
                                }
                            } else {
                                console.warn('Failed to geocode address:', address);
                            }
                        })
                    );
                }

                console.log('Waiting for all geocoding to complete...');
                await Promise.all(distancePromises);
                console.log('All geocoding completed, filtering and sorting by distance');
                
                // Filter by 200km distance - trigger applyFilter via input event
                const searchInput = document.getElementById('homeSearchInput');
                if (searchInput) {
                    // Trigger the filter which will check for GPS active and filter by distance
                    const inputEvent = new Event('input', { bubbles: true });
                    searchInput.dispatchEvent(inputEvent);
                } else {
                    // Fallback: just sort if search input not found
                    sortByDistance();
                }
            }

            // Sort cards by distance
            function sortByDistance() {
                const grid = document.getElementById('saloonsGrid');
                if (!grid) return;
                
                const cards = Array.from(grid.getElementsByClassName('saloon-card'));
                const visibleCards = cards.filter(card => card.style.display !== 'none');
                
                visibleCards.sort((a, b) => {
                    const distA = parseFloat(a.getAttribute('data-distance')) || Infinity;
                    const distB = parseFloat(b.getAttribute('data-distance')) || Infinity;
                    return distA - distB;
                });

                // Reorder in DOM
                visibleCards.forEach(card => grid.appendChild(card));
            }

            // Toggle GPS search
            if (gpsBtn) {
                console.log('GPS button found, attaching event listener');
                gpsBtn.addEventListener('click', () => {
                    try {
                        console.log('GPS button clicked, isGpsActive:', isGpsActive);
                        const grid = document.getElementById('saloonsGrid');
                        if (isGpsActive) {
                            // Deactivate GPS search
                            console.log('Deactivating GPS search');
                            isGpsActive = false;
                            userLocation = null;
                            isRequestingLocation = false;
                            stopWatchingLocation(); // Stop continuous tracking and intervals
                            gpsBtn.classList.remove('active');
                            
                            const statusEl = document.getElementById('gpsStatus');
                            if (statusEl) {
                                statusEl.style.display = 'none';
                            }
                            
                            // Hide distances
                            if (grid) {
                                const cards = Array.from(grid.getElementsByClassName('saloon-card'));
                                cards.forEach(card => {
                                    const distanceSpan = card.querySelector('.saloon-distance');
                                    if (distanceSpan) {
                                        distanceSpan.style.display = 'none';
                                    }
                                    card.setAttribute('data-distance', '');
                                });
                            }
                            
                            // Reapply current filter
                            const searchInput = document.getElementById('homeSearchInput');
                            if (searchInput) {
                                const event = new Event('input');
                                searchInput.dispatchEvent(event);
                            }
                    } else {
                        // Only activate if not already requesting
                        if (!isRequestingLocation && !isGpsActive) {
                            console.log('Activating GPS search, calling getUserLocation()');
                            getUserLocation();
                        } else {
                            console.log('GPS already active or request in progress, skipping');
                        }
                    }
                    } catch (error) {
                        console.error('Error in GPS button click handler:', error);
                        // Reset button state on error
                        if (gpsBtn) {
                            gpsBtn.classList.remove('loading');
                            gpsBtn.classList.remove('active');
                            gpsBtn.disabled = false;
                        }
                        alert('An error occurred with GPS functionality. Please try again.');
                    }
                });
            } else {
                console.error('GPS button not found! Button ID: gpsBtn');
            }
        })();

        (function() {
            const slider = document.getElementById('heroSlider');
            if (!slider) return;

            const slides = Array.prototype.slice.call(slider.querySelectorAll('.hero-slide'));
            const dots = Array.prototype.slice.call(slider.querySelectorAll('.hero-dot'));
            const prevBtn = slider.querySelector('[data-hero-prev]');
            const nextBtn = slider.querySelector('[data-hero-next]');

            let currentIndex = 0;
            let timerId = null;
            const INTERVAL = 3000;

            // #region agent log
            function logDebug(message, data) {
                fetch('http://127.0.0.1:7242/ingest/19dbfd65-af3c-4960-a38f-4b58e38246f6', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        location: 'index.php:showSlide',
                        message: message,
                        data: data,
                        timestamp: Date.now(),
                        sessionId: 'debug-session',
                        runId: 'run1',
                        hypothesisId: 'A'
                    })
                }).catch(() => {});
            }
            // #endregion agent log

            // Force reload images by updating background-image URLs with cache-busting
            // Always reload to ensure fresh images on each slide display
            const pageTimestamp = <?php echo $pageTimestamp; ?>;
            
            function forceImageReload(slideElement, slideIndex) {
                const imgEl = slideElement.querySelector('.hero-slide-img');
                if (!imgEl) return;
                
                // Skip reload for external placeholder URLs (Unsplash, etc.)
                if (imgEl.src && (imgEl.src.includes('unsplash.com') || imgEl.src.includes('placeholder') || imgEl.src.startsWith('data:image'))) {
                    // #region agent log
                    logDebug('Skipping reload for external placeholder', {
                        slideIndex: slideIndex,
                        isProblematicSlide: [3, 4, 7].includes(slideIndex),
                        src: imgEl.src.substring(0, 100)
                    });
                    // #endregion agent log
                    return;
                }
                
                const currentSrc = imgEl.src;
                const versions = <?php echo json_encode($imageVersions); ?>;
                const pageTs = pageTimestamp;
                
                // Extract image key from current src
                const imageKeyMap = {
                    'hair-service.jpg': 'hair',
                    'nail-service.jpg': 'nail',
                    'facial.jpg': 'facial',
                    'makeover.jpg': 'makeover',
                    'body-spa.jpg': 'body-spa',
                    'manicure-pedicure.jpg': 'mani-pedi',
                    'consultation.jpg': 'consultation'
                };
                
                let imageKey = null;
                for (const [filename, key] of Object.entries(imageKeyMap)) {
                    if (currentSrc.includes(filename)) {
                        imageKey = key;
                        break;
                    }
                }
                
                if (imageKey && versions[imageKey]) {
                    const version = versions[imageKey];
                    const imagePath = 'assets/images/hero/' + Object.keys(imageKeyMap).find(k => imageKeyMap[k] === imageKey);
                    const uniqueTimestamp = Date.now();
                    const encodedVersion = encodeURIComponent(version);
                    const newUrl = imagePath + '?v=' + encodedVersion + '&p=' + pageTs + '&_t=' + uniqueTimestamp;
                    
                    // #region agent log
                    logDebug('Force reloading img element with cache-busting', {
                        imageKey: imageKey,
                        fileVersion: version,
                        pageTimestamp: pageTs,
                        uniqueTimestamp: uniqueTimestamp,
                        oldSrc: currentSrc,
                        newSrc: newUrl,
                        slideIndex: slideIndex
                    });
                    // #endregion agent log
                    
                    // Force reload by updating src - browsers respect img src changes better than background-image
                    // Add error handler to detect loading failures
                    imgEl.onerror = function() {
                        // #region agent log
                        logDebug('Image failed to load', {
                            imageKey: imageKey,
                            attemptedSrc: newUrl,
                            slideIndex: slideIndex,
                            isProblematicSlide: [3, 4, 7].includes(slideIndex)
                        });
                        // #endregion agent log
                    };
                    
                    imgEl.onload = function() {
                        // #region agent log
                        logDebug('Image loaded successfully', {
                            imageKey: imageKey,
                            src: imgEl.src,
                            slideIndex: slideIndex,
                            naturalWidth: imgEl.naturalWidth,
                            naturalHeight: imgEl.naturalHeight,
                            isProblematicSlide: [3, 4, 7].includes(slideIndex)
                        });
                        // #endregion agent log
                    };
                    
                    // Clear src first to force reload, then set new src
                    const oldSrc = imgEl.src;
                    imgEl.src = '';
                    // Small delay to ensure browser processes the clear
                    setTimeout(function() {
                        imgEl.src = newUrl;
                        
                        // #region agent log
                        logDebug('Image src updated', {
                            imageKey: imageKey,
                            oldSrc: oldSrc,
                            newSrc: newUrl,
                            finalSrc: imgEl.src,
                            slideIndex: slideIndex,
                            isProblematicSlide: [3, 4, 7].includes(slideIndex)
                        });
                        // #endregion agent log
                    }, 10);
                } else {
                    // #region agent log
                    logDebug('Could not determine image key or version', {
                        currentSrc: currentSrc,
                        slideIndex: slideIndex,
                        imageKey: imageKey,
                        hasVersion: imageKey ? !!versions[imageKey] : false,
                        isProblematicSlide: [3, 4, 7].includes(slideIndex)
                    });
                    // #endregion agent log
                }
            }

            function showSlide(index) {
                if (!slides.length) return;
                const safeIndex = ((index % slides.length) + slides.length) % slides.length;
                
                // #region agent log
                const activeSlide = slides[safeIndex];
                const imgElement = activeSlide ? activeSlide.querySelector('.hero-slide-img') : null;
                const imgSrc = imgElement ? imgElement.src : 'N/A';
                logDebug('showSlide called', {
                    index: index,
                    safeIndex: safeIndex,
                    slideIndex: safeIndex,
                    imgSrc: imgSrc,
                    imgFound: !!imgElement,
                    slideClass: activeSlide ? activeSlide.className : 'N/A',
                    isProblematicSlide: [3, 4, 7].includes(safeIndex)
                });
                // #endregion agent log

                slides.forEach(function(slide, i) {
                    if (i === safeIndex) {
                        slide.classList.add('is-active');
                        // Force reload image when slide becomes active
                        forceImageReload(slide, i);
                        // #region agent log
                        const imgEl = slide.querySelector('.hero-slide-img');
                        if (imgEl) {
                            logDebug('Active slide img element', {
                                slideIndex: i,
                                imgSrc: imgEl.src,
                                imgComplete: imgEl.complete,
                                imgNaturalWidth: imgEl.naturalWidth,
                                imgNaturalHeight: imgEl.naturalHeight,
                                isProblematicSlide: [3, 4, 7].includes(i)
                            });
                        } else {
                            logDebug('No img element found in slide', {
                                slideIndex: i,
                                slideHTML: slide.innerHTML.substring(0, 200),
                                isProblematicSlide: [3, 4, 7].includes(i)
                            });
                        }
                        // #endregion agent log
                    } else {
                        slide.classList.remove('is-active');
                    }
                });
                dots.forEach(function(dot, i) {
                    if (i === safeIndex) {
                        dot.classList.add('is-active');
                        dot.setAttribute('aria-current', 'true');
                    } else {
                        dot.classList.remove('is-active');
                        dot.removeAttribute('aria-current');
                    }
                });
                currentIndex = safeIndex;
            }

            function nextSlide() {
                showSlide(currentIndex + 1);
            }

            function prevSlide() {
                showSlide(currentIndex - 1);
            }

            function startTimer() {
                stopTimer();
                timerId = window.setInterval(nextSlide, INTERVAL);
            }

            function stopTimer() {
                if (timerId !== null) {
                    window.clearInterval(timerId);
                    timerId = null;
                }
            }

            if (nextBtn) {
                nextBtn.addEventListener('click', function() {
                    nextSlide();
                    startTimer();
                });
            }

            if (prevBtn) {
                prevBtn.addEventListener('click', function() {
                    prevSlide();
                    startTimer();
                });
            }

            if (dots.length) {
                dots.forEach(function(dot, index) {
                    dot.addEventListener('click', function() {
                        showSlide(index);
                        startTimer();
                    });
                });
            }

            slider.addEventListener('mouseenter', stopTimer);
            slider.addEventListener('mouseleave', startTimer);

            // #region agent log
            logDebug('Slideshow initialized', {
                totalSlides: slides.length,
                imageVersions: <?php echo json_encode($imageVersions); ?>
            });
            
            // Log all slide images on page load
            slides.forEach(function(slide, i) {
                const imgEl = slide.querySelector('.hero-slide-img');
                if (imgEl) {
                    logDebug('Slide img element on load', {
                        slideIndex: i,
                        imgSrc: imgEl.src,
                        imgComplete: imgEl.complete,
                        imgNaturalWidth: imgEl.naturalWidth,
                        imgNaturalHeight: imgEl.naturalHeight,
                        isActive: slide.classList.contains('is-active'),
                        isProblematicSlide: [3, 4, 7].includes(i)
                    });
                } else {
                    logDebug('No img element found on slide load', {
                        slideIndex: i,
                        slideHTML: slide.innerHTML.substring(0, 200),
                        isProblematicSlide: [3, 4, 7].includes(i)
                    });
                }
            });
            // #endregion agent log

            showSlide(0);
            startTimer();
        })();
    </script>
</body>
</html>
