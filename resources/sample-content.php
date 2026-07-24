<?php
/**
 * resources/sample-content.php
 *
 * Demo content that ships with the default PlugPHP template. The installer
 * seeds this into empty tables on a fresh install (WordPress-style), so a
 * freshly downloaded site is populated and informative instead of blank.
 * Edit or delete any of it from the admin dashboard once you're building
 * your own site.
 *
 * Each row's keys match its table's columns, so the installer can insert
 * them directly via Database::insert(). created_at/updated_at use DB
 * defaults, so they're intentionally omitted.
 */

return [

    // ---- Services = what PlugPHP gives you (features / benefits) ----
    'services' => [
        [
            'title'            => 'Modular architecture',
            'slug'             => 'modular-architecture',
            'summary'          => 'Add only the modules a site needs — each one is independently removable.',
            'description'      => "Every feature lives in its own module under /modules: blog, services, projects, contact form, auth, and the admin dashboard. Turn a module off during install and it simply isn't there; hide a content module from the public site at runtime without losing its data.\n\nBecause modules share one small base class and never reach into each other, you can delete one without breaking the rest — and add your own the same way an AI agent would, by following the module's SKILL.md.",
            'icon'             => 'bi-grid',
            'display_order'    => 1,
            'meta_title'       => 'Modular architecture — PlugPHP',
            'meta_description' => 'PlugPHP is built from independently removable modules, so every site ships with only what it needs.',
        ],
        [
            'title'            => 'Security by default',
            'slug'             => 'security-by-default',
            'summary'          => 'Prepared statements, CSRF on every form, hashed passwords, hardened headers — on from request one.',
            'description'      => "The database layer exposes no raw-query path at all, so SQL injection isn't a rule you have to remember — it's a missing code path. Every state-changing request is CSRF-checked, passwords are hashed with the modern default, sessions regenerate on login, and password-reset tokens are stored only as hashes.\n\nBaseline security headers (CSP, X-Frame-Options, Referrer-Policy, HSTS in production) go out on every response, and uploads are whitelisted, MIME-sniffed, and re-encoded before they ever touch disk.",
            'icon'             => 'bi-shield-check',
            'display_order'    => 2,
            'meta_title'       => 'Security by default — PlugPHP',
            'meta_description' => 'Prepared statements, CSRF protection, hashed passwords, and hardened headers come standard in PlugPHP.',
        ],
        [
            'title'            => 'SEO & performance built in',
            'slug'             => 'seo-and-performance',
            'summary'          => 'Auto meta tags, canonical, Open Graph, and JSON-LD from your own fields — on fast, server-rendered pages.',
            'description'      => "Every content page emits its title, meta description, canonical URL, Open Graph tags, and JSON-LD structured data automatically from the record's own fields — no hand-authoring, no plugin. Pages are server-rendered PHP with lazy-loaded images and explicit dimensions, so there's no SPA bundle weight and no layout shift.\n\nThe result is a site that scores well on Lighthouse and agentic-browsing checks by construction, not because someone remembered to optimise it afterward.",
            'icon'             => 'bi-graph-up',
            'display_order'    => 3,
            'meta_title'       => 'SEO and performance — PlugPHP',
            'meta_description' => 'PlugPHP auto-generates meta, canonical, Open Graph, and JSON-LD on fast server-rendered pages.',
        ],
        [
            'title'            => 'Agent-ready',
            'slug'             => 'agent-ready',
            'summary'          => 'Ships with SKILL.md files so an AI coding agent extends it correctly — burning far fewer credits.',
            'description'      => "PlugPHP is designed to be built on by AI coding agents. A master SKILL.md plus one per module encode the rules — no raw SQL, always escape output, never reimplement auth, CSRF on every form — so an agent extends the kit correctly instead of improvising from scratch.\n\nThat means a huge share of the boilerplate an agent would otherwise re-derive (routing, auth, a DB layer, a mailer) is already solved, so its credits go into the part you actually care about: your site.",
            'icon'             => 'bi-robot',
            'display_order'    => 4,
            'meta_title'       => 'Agent-ready — PlugPHP',
            'meta_description' => 'PlugPHP ships with SKILL.md instruction files so AI coding agents extend it correctly and cheaply.',
        ],
        [
            'title'            => 'Five-minute web installer',
            'slug'             => 'web-installer',
            'summary'          => 'A GUI installer: pick modules, set the database, create an admin. No command line.',
            'description'      => "Setup is a browser wizard, not a terminal session. It checks your environment, lets you pick which modules to install, tests the database connection before it touches anything, runs the migrations, and creates your first admin user.\n\nWhen it's done it writes your configuration and locks itself, then tells you to delete it — the same five-minute feel as a classic CMS install, with none of the CLI.",
            'icon'             => 'bi-magic',
            'display_order'    => 5,
            'meta_title'       => 'Web installer — PlugPHP',
            'meta_description' => 'A GUI web installer sets up PlugPHP in about five minutes with no command line required.',
        ],
        [
            'title'            => 'Runs on shared hosting',
            'slug'             => 'runs-on-shared-hosting',
            'summary'          => 'Vanilla PHP on the cheapest cPanel plan — no build step, no framework weight.',
            'description'      => "PlugPHP is vanilla PHP with one small Composer dependency for email. There's no build step to run on the server, no framework to keep updated, and nothing that needs a VPS. It's designed to deploy by uploading files and pointing your domain at the public/ folder.\n\nThat makes it a genuine fit for the cheapest shared and cPanel hosting — the plan you already pay for — while still giving you a real admin dashboard and secure defaults.",
            'icon'             => 'bi-hdd-network',
            'display_order'    => 6,
            'meta_title'       => 'Runs on shared hosting — PlugPHP',
            'meta_description' => 'PlugPHP runs on the cheapest shared and cPanel hosting with no build step and no framework weight.',
        ],
    ],

    // ---- Projects = the kinds of sites/businesses you can build with PlugPHP ----
    'projects' => [
        [
            'title'            => 'Rise & Crumb — neighbourhood bakery',
            'slug'             => 'rise-and-crumb-bakery',
            'client_name'      => 'Example: local shop',
            'summary'          => 'A warm little bakery site — menu, story, and a spam-proof order enquiry form.',
            'description'      => "A local bakery doesn't need a page builder or a monthly plugin bill — it needs a fast, findable site it can update itself. On PlugPHP, the Services module becomes the menu, the Blog carries seasonal specials and news, and the built-in contact form handles order enquiries with CSRF protection and IP rate-limiting so it never drowns in spam.\n\nEverything is editable from the dashboard, the pages score well on mobile PageSpeed, and the whole thing runs on a few dollars a month of shared hosting.",
            'featured_image'   => null,
            'gallery_images'   => null,
            'project_url'      => null,
            'completed_at'     => '2026-03-04',
            'meta_title'       => 'Bakery website example — built on PlugPHP',
            'meta_description' => 'How a neighbourhood bakery site is built on PlugPHP: menu, news, and a spam-proof order form.',
        ],
        [
            'title'            => 'Studio Vero — freelance designer portfolio',
            'slug'             => 'studio-vero-portfolio',
            'client_name'      => 'Example: solo freelancer',
            'summary'          => 'A minimal portfolio that lets the work speak — and ranks for it.',
            'description'      => "For a freelancer, the site is the pitch. PlugPHP's Projects module becomes the case-study gallery — each piece with a featured image, a gallery, and automatic Article and CreativeWork structured data so it's discoverable. The Blog doubles as a working journal, and the About page tells the story.\n\nNo builder lock-in, no plugin subscriptions, and clean semantic markup a designer can restyle pixel-by-pixel.",
            'featured_image'   => null,
            'gallery_images'   => null,
            'project_url'      => null,
            'completed_at'     => '2026-02-12',
            'meta_title'       => 'Portfolio website example — built on PlugPHP',
            'meta_description' => 'How a freelance designer portfolio is built on PlugPHP with case studies, a journal, and built-in SEO.',
        ],
        [
            'title'            => 'Meridian Advisory — boutique consultancy',
            'slug'             => 'meridian-advisory',
            'client_name'      => 'Example: small firm',
            'summary'          => 'A credible, fast site a small consultancy can run without a developer on call.',
            'description'      => "A boutique consulting firm needs to look established and stay current. PlugPHP's Services module lays out the practice areas, Projects presents client case studies, and the Blog carries thought-leadership pieces — all with secure auth and an admin dashboard the team runs themselves.\n\nBecause security, SEO, and performance are defaults rather than add-ons, the site is credible from day one and cheap to keep online.",
            'featured_image'   => null,
            'gallery_images'   => null,
            'project_url'      => null,
            'completed_at'     => '2026-01-20',
            'meta_title'       => 'Consulting website example — built on PlugPHP',
            'meta_description' => 'How a boutique consultancy site is built on PlugPHP: practice areas, case studies, and a team-run dashboard.',
        ],
    ],

    // ---- Blog = notes about PlugPHP ----
    'posts' => [
        [
            'title'            => 'Why we built PlugPHP',
            'slug'             => 'why-we-built-plugphp',
            'excerpt'          => 'WordPress developers were getting squeezed. PlugPHP is the alternative — without giving up the plug-and-play feel.',
            'body'             => "WordPress developers — especially UI-first, Elementor-trained ones — have been getting squeezed from two directions: page-builder pricing and feature decisions that trend against developers, and an ecosystem increasingly absorbing AI into itself rather than handing developers a real alternative outside of it.\n\nAI coding agents make it possible to build outside WordPress, but in practice a huge share of an agent's credits gets burned rebuilding the same boilerplate — routing, auth, a database layer, a mailer — before anyone touches the part that matters: the site.\n\nPlugPHP is the answer: a modular, vanilla-PHP starter kit that keeps WordPress's plug-and-play feel — a web installer, a module picker, an admin dashboard — while being secure and SEO-hardened by default, and explicitly agent-ready so the boilerplate is already solved.",
            'featured_image'   => null,
            'meta_title'       => 'Why we built PlugPHP',
            'meta_description' => 'The story behind PlugPHP: a modular, agent-ready alternative for developers squeezed by the WordPress ecosystem.',
            'published_at'     => '2026-03-14 09:00:00',
        ],
        [
            'title'            => 'Vanilla PHP is underrated on shared hosting',
            'slug'             => 'vanilla-php-on-shared-hosting',
            'excerpt'          => 'You can run a genuinely modern, secure, fast site on the cheapest plan you already pay for.',
            'body'             => "Shared hosting has a reputation problem, but the constraints that come with it are exactly what keep a site fast and cheap: no build step, no framework to update, no VPS to babysit.\n\nServer-rendered PHP means there's no SPA bundle to ship, so pages are fast by construction. Add browser caching, lazy-loaded images with explicit dimensions, and a CDN in front, and a PlugPHP site on a few dollars a month hits the same Lighthouse numbers people assume require a modern JS stack.\n\nThe trick isn't a bigger server — it's shipping less to the browser and getting the defaults right.",
            'featured_image'   => null,
            'meta_title'       => 'Vanilla PHP is underrated on shared hosting',
            'meta_description' => 'How PlugPHP delivers a modern, secure, fast site on the cheapest shared and cPanel hosting.',
            'published_at'     => '2026-02-28 09:00:00',
        ],
        [
            'title'            => 'How the SKILL.md system makes PlugPHP agent-ready',
            'slug'             => 'skill-md-agent-ready',
            'excerpt'          => 'Point an AI agent at PlugPHP and it extends the kit correctly — not from scratch.',
            'body'             => "Most of what makes a codebase hard for an AI agent isn't the code — it's the unwritten rules. PlugPHP writes them down.\n\nA master SKILL.md defines what's editable and what's locked, plus the hard rules: no raw SQL outside the database layer, always escape output, never reimplement auth or CSRF, send email only through the mailer, CSRF on every state-changing route. Each module adds its own SKILL.md with its schema, routes, and the specific traps to avoid.\n\nThe payoff is twofold: the agent doesn't re-derive boilerplate it can't see is already solved, and it doesn't quietly introduce a security bug because it didn't know the convention. Fewer credits, safer output.",
            'featured_image'   => null,
            'meta_title'       => 'How SKILL.md makes PlugPHP agent-ready',
            'meta_description' => 'PlugPHP ships instruction files so AI coding agents extend it correctly and cheaply instead of improvising.',
            'published_at'     => '2026-02-09 09:00:00',
        ],
        [
            'title'            => 'Security you don\'t have to think about',
            'slug'             => 'security-you-dont-think-about',
            'excerpt'          => 'CSRF, prepared statements, and hardened headers — enforced structurally, not left to memory.',
            'body'             => "The best security control is the one you can't forget to apply. PlugPHP leans on that idea.\n\nThe database layer exposes no raw-query method, so injection isn't a discipline — it's a missing path. CSRF verification is the first line of every state-changing handler. Passwords are hashed with the modern default and sessions regenerate on login. Password-reset tokens are stored only as SHA-256 hashes with an expiry, and the reset flow gives the same response whether or not the email exists, so it can't be used to enumerate users.\n\nUploads are whitelisted, MIME-sniffed, and re-encoded before they're stored with execute permission stripped. None of this is optional, and none of it depends on a developer remembering to switch it on.",
            'featured_image'   => null,
            'meta_title'       => "Security you don't have to think about — PlugPHP",
            'meta_description' => 'How PlugPHP enforces CSRF, prepared statements, safe uploads, and hardened headers by construction.',
            'published_at'     => '2026-01-22 09:00:00',
        ],
    ],
];
