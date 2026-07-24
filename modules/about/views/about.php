<?php
/**
 * modules/about/views/about.php — static About page (Option A).
 * Default template content tells PlugPHP's story. Edit freely for your site.
 */
$pageTitle = 'About PlugPHP';
$metaDescription = 'PlugPHP is a modular, agent-ready vanilla-PHP starter kit for shared hosting — secure and SEO-hardened by default.';

$principles = [
    ['Secure by default', 'Prepared statements only, CSRF on every form, hashed passwords, and hardened headers — enforced structurally, not left to memory.'],
    ['Modular', 'Every feature is an independently removable module. Install only what a site needs; hide the rest.'],
    ['No lock-in', 'Vanilla PHP and GPL-licensed. No page builder, no proprietary format, no monthly plugin bill.'],
    ['Runs anywhere', 'Designed for the cheapest shared and cPanel hosting — no build step, no VPS, no CLI to operate it.'],
    ['Agent-ready', 'Ships with SKILL.md instruction files so an AI coding agent extends it correctly and cheaply.'],
    ['SEO & performance', 'Server-rendered pages with automatic meta, canonical, Open Graph, and JSON-LD from your own fields.'],
];
?>
<article class="container container--narrow section">
    <span class="eyebrow">About</span>
    <h1 class="h-page">About PlugPHP</h1>
    <p class="lead lead--wide" style="margin-top:20px;font-size:clamp(17px,2.2vw,20px)">
        PlugPHP is a modular, agent-ready vanilla-PHP starter kit for shared hosting. It keeps WordPress's
        plug-and-play feel — a web installer, a module picker, an admin dashboard — while staying secure and
        SEO-hardened by default. This site is its default template.
    </p>

    <section style="margin-top:44px" aria-labelledby="about-why">
        <span class="eyebrow">01 / Why it exists</span>
        <h2 id="about-why" style="font-weight:700;font-size:24px;letter-spacing:-.02em;margin-top:10px">Good software on a modest budget</h2>
        <p style="font-size:16px;line-height:1.65;color:var(--body);margin-top:12px;max-width:65ch">
            WordPress developers have been getting squeezed — by page-builder pricing and by an ecosystem
            absorbing AI into itself rather than handing developers an alternative. AI coding agents make it
            possible to build outside WordPress, but a huge share of their credits gets burned rebuilding the
            same boilerplate every time: routing, auth, a database layer, a mailer.
        </p>
        <p style="font-size:16px;line-height:1.65;color:var(--body);margin-top:14px;max-width:65ch">
            PlugPHP solves that once. The plumbing is already built and hardened, so your hours — and your
            agent's credits — go into the part that actually matters: your site.
        </p>
    </section>

    <section style="margin-top:48px" aria-labelledby="about-principles">
        <span class="eyebrow">02 / Principles</span>
        <h2 id="about-principles" style="font-weight:700;font-size:24px;letter-spacing:-.02em;margin:10px 0 20px">What it stands for</h2>
        <div class="grid grid--services">
            <?php foreach ($principles as $p): ?>
                <div class="card">
                    <h3 style="font-weight:700;font-size:16px;color:var(--ink)"><?= e($p[0]) ?></h3>
                    <p style="font-size:14.5px;line-height:1.55;color:var(--muted-2);margin-top:8px"><?= e($p[1]) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section style="margin-top:44px" aria-labelledby="about-license">
        <span class="eyebrow">03 / License</span>
        <h2 id="about-license" style="font-weight:700;font-size:24px;letter-spacing:-.02em;margin-top:10px">Free and open source</h2>
        <p style="font-size:16px;line-height:1.65;color:var(--body);margin-top:12px;max-width:65ch">
            PlugPHP is released under the GPL-3.0 license — chosen deliberately so it stays free for the
            community it was built for, and can't be forked, closed up, and resold back to it.
        </p>
    </section>

    <p class="attribution">Built with PlugPHP — by Kabiru Sambo / Bubble Bot Solutions</p>
</article>
