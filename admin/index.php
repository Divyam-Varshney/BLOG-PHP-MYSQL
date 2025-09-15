<?php 
// ============================
// Page: 404.php
// Purpose: Enhanced styled "404 Not Found" page with hero section + bubble animation
// ============================

require_once __DIR__ . '/../data/Functions.php';
app_start_session();

// Detect current user (if logged in)
$current_user = app_get_current_user();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 Not Found - Blog</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">

    <style>
    :root {
        --bg-primary: #121212;
        --bg-secondary: #1f1f1f;
        --accent-start: #ff9b44;
        --accent-end: #fc6075;
    }

    /* --------- Hero (top banner) --------- */
    .hero-section {
        background: linear-gradient(135deg, var(--bg-primary), var(--bg-secondary));
        text-align: center;
        position: relative;
        overflow: hidden;
        padding: 120px 20px;
        color: #fff;
        min-height: 100vh;
        display: flex;
        align-items: center;
        animation: fadeIn 1.2s ease;
    }

    .hero-section h1 {
        font-size: 4rem;
        font-weight: 800;
        letter-spacing: -1px;
    }

    .hero-section h1 span {
        color: var(--accent-end);
    }

    .hero-section p {
        color: rgba(255, 255, 255, .85);
        max-width: 720px;
        margin: 1.5rem auto;
        font-size: 1.25rem;
    }

    @media (max-width: 768px) {
        .hero-section h1 {
            font-size: 2.5rem;
        }

        .hero-section p {
            font-size: 1rem;
        }
    }

    /* Floating blobs */
    .hero-section::before,
    .hero-section::after {
        content: "";
        position: absolute;
        width: 220px;
        height: 220px;
        border-radius: 50%;
        filter: blur(120px);
        opacity: .95;
    }

    .hero-section::before {
        background: var(--accent-start);
        top: -40px;
        left: -60px;
    }

    .hero-section::after {
        background: var(--accent-end);
        bottom: -40px;
        right: -60px;
    }

    /* --------- Bubble animation --------- */
    .hero-bubbles {
        position: absolute;
        inset: 0;
        overflow: hidden;
        z-index: 0;
        pointer-events: none;
    }

    .hero-bubbles span {
        position: absolute;
        bottom: -120px;
        width: 22px;
        height: 22px;
        background: radial-gradient(circle at 30% 30%, var(--accent-start), var(--accent-end));
        border-radius: 50%;
        animation: rise 14s infinite ease-in;
        filter: blur(4px);
        opacity: .6;
    }

    .hero-bubbles span:nth-child(2) {
        left: 15%;
        width: 28px;
        height: 28px;
        animation-duration: 18s;
        animation-delay: 2s
    }

    .hero-bubbles span:nth-child(3) {
        left: 35%;
        width: 18px;
        height: 18px;
        animation-duration: 12s;
        animation-delay: 4s
    }

    .hero-bubbles span:nth-child(4) {
        left: 55%;
        width: 34px;
        height: 34px;
        animation-duration: 20s;
        animation-delay: 1s
    }

    .hero-bubbles span:nth-child(5) {
        left: 75%;
        width: 24px;
        height: 24px;
        animation-duration: 16s;
        animation-delay: 3s
    }

    .hero-bubbles span:nth-child(6) {
        left: 90%;
        width: 20px;
        height: 20px;
        animation-duration: 22s;
        animation-delay: 6s
    }

    @keyframes rise {
        0% {
            transform: translateY(0) scale(1);
            opacity: .4
        }

        50% {
            opacity: .9;
            transform: translateY(-50vh) scale(1.2)
        }

        100% {
            transform: translateY(-100vh) scale(.8);
            opacity: 0
        }
    }

    .hero-section .container {
        position: relative;
        z-index: 1;
    }

    /* --------- Buttons --------- */
    .btn-primary {
        background: linear-gradient(90deg, var(--accent-start), var(--accent-end));
        border: none;
        color: #111;
        font-weight: 600;
        padding: .75rem 1.2rem;
        border-radius: 10px;
        transition: all .3s ease;
    }

    .btn-primary:hover {
        box-shadow: 0 0 18px rgba(252, 96, 117, 0.5);
        transform: translateY(-2px);
    }

    .btn-outline-light {
        border: 2px solid #fff;
        padding: .7rem 1.1rem;
        border-radius: 10px;
        transition: all .3s ease;
    }

    .btn-outline-light:hover {
        background: #fff;
        color: #000;
    }

    .btn-ghost {
        color: #fff;
        text-decoration: underline;
        transition: opacity .3s ease;
    }

    .btn-ghost:hover {
        opacity: 0.7;
    }
    </style>
</head>

<body>

    <!-- ============================
         Hero Section (404)
    ============================ -->
    <section class="hero-section">
        <!-- Animated bubbles -->
        <div class="hero-bubbles" aria-hidden="true">
            <?php for ($i=0; $i<20; $i++): ?><span></span><?php endfor; ?>
        </div>

        <div class="container">
            <h1 class="display-1 fw-bold">404 <span>Not Found</span></h1>
            <p class="mb-4">Oops! The page you’re looking for doesn’t exist or may have been moved.
                Let’s get you back on track 🚀</p>

            <?php if (!$current_user): ?>
            <div class="d-grid gap-3 d-sm-flex justify-content-center">
                <a href="/../user/login.php" class="btn btn-primary btn-lg">
                    <i class="bi bi-box-arrow-in-right"></i> Login
                </a>
                <a href="/../user/register.php" class="btn btn-outline-light btn-lg">
                    <i class="bi bi-person-plus"></i> Create Account
                </a>
            </div>
            <?php else: ?>
            <a href="/../user/dashboard.php" class="btn btn-primary btn-lg mb-3">
                <i class="bi bi-speedometer2"></i> Go to Dashboard
            </a>
            <?php endif; ?>

            <div class="mt-3">
                <a href="../index.php" class="btn btn-lg">Back to Home</a>
            </div>


        </div>
    </section>

</body>

</html>