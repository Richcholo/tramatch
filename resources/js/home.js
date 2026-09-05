import Lenis from 'lenis';
import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

gsap.registerPlugin(ScrollTrigger);

const lenis = new Lenis({
    lerp: 0.075,
    wheelMultiplier: 0.9,
    touchMultiplier: 1.4,
    smoothWheel: true,
    respectReducedMotion: false
});

window.lenis = lenis;

lenis.on('scroll', ScrollTrigger.update);

function raf(time) {
    lenis.raf(time);
    requestAnimationFrame(raf);
}
requestAnimationFrame(raf);

document.addEventListener('click', (e) => {
    const link = e.target.closest('a[href^="#"]');
    if (!link) return;

    const href = link.getAttribute('href');
    if (!href || href.length < 2) return;

    e.preventDefault();

    if (href === '#top' || href === '#') {
        lenis.scrollTo(0, { duration: 1.6, easing: (t) => 1 - Math.pow(1 - t, 4) });
        return;
    }

    const target = document.querySelector(href);
    if (!target) return;

    lenis.scrollTo(target, { duration: 1.6, easing: (t) => 1 - Math.pow(1 - t, 4) });
});

const paintBg = (color) =>
    gsap.to('body', {
        backgroundColor: color,
        duration: 0.9,
        ease: 'power2.out',
        overwrite: 'auto',
    });

document.querySelectorAll('[data-bg]').forEach((section) => {
    const color = section.dataset.bg;
    ScrollTrigger.create({
        trigger: section,
        start: 'top 60%',
        end: 'bottom 60%',
        onEnter: () => paintBg(color),
        onEnterBack: () => paintBg(color),
    });
});

const meta = document.querySelector('meta[name="theme-color"]');
if (meta) {
    document.querySelectorAll('[data-theme-color]').forEach((section) => {
        ScrollTrigger.create({
            trigger: section,
            start: 'top 60%',
            end: 'bottom 60%',
            onEnter: () => (meta.content = section.dataset.themeColor),
            onEnterBack: () => (meta.content = section.dataset.themeColor),
        });
    });
}

gsap.utils.toArray('[data-reveal]').forEach((el) => {
    gsap.fromTo(
        el,
        { y: 48, opacity: 0 },
        {
            y: 0,
            opacity: 1,
            duration: 1.1,
            ease: 'power3.out',
            delay: parseFloat(el.dataset.revealDelay || 0),
            scrollTrigger: { trigger: el, start: 'top 85%' },
        }
    );
});

gsap.utils.toArray('[data-line]').forEach((el) => {
    gsap.fromTo(
        el,
        { scaleX: 0, transformOrigin: 'left center' },
        {
            scaleX: 1,
            duration: 1.2,
            ease: 'power3.inOut',
            scrollTrigger: { trigger: el, start: 'top 90%' },
        }
    );
});

gsap.utils.toArray('[data-parallax]').forEach((el) => {
    const speed = parseFloat(el.dataset.parallax || 8);
    gsap.fromTo(
        el,
        { yPercent: speed },
        {
            yPercent: -speed,
            ease: 'none',
            scrollTrigger: {
                trigger: el.closest('section') || el,
                start: 'top bottom',
                end: 'bottom top',
                scrub: 0.6,
            },
        }
    );
});

const heroArt = document.querySelector('[data-hero-art]');
if (heroArt) {
    gsap.to(heroArt, {
        yPercent: 20,
        ease: 'none',
        scrollTrigger: {
            trigger: '#hero',
            start: 'top top',
            end: 'bottom top',
            scrub: true,
        },
    });
}

const wordmark = document.querySelector('[data-wordmark]');
if (wordmark) {
    gsap.fromTo(
        wordmark,
        { yPercent: 45 },
        {
            yPercent: 0,
            ease: 'none',
            scrollTrigger: {
                trigger: 'footer',
                start: 'top bottom',
                end: 'bottom bottom',
                scrub: 0.6,
            },
        }
    );
}

const hero = document.querySelector('#hero');
if (hero && window.matchMedia('(pointer: fine)').matches) {
    hero.addEventListener('pointermove', (e) => {
        const x = e.clientX / window.innerWidth - 0.5;
        const y = e.clientY / window.innerHeight - 0.5;
        document.querySelectorAll('[data-depth]').forEach((el) => {
            gsap.to(el, {
                x: x * 40 * parseFloat(el.dataset.depth),
                y: y * 40 * parseFloat(el.dataset.depth),
                duration: 0.9,
                ease: 'power2.out',
            });
        });
    });
}

const rotator = document.querySelector('[data-rotator]');
if (rotator) {
    const words = JSON.parse(rotator.dataset.words);
    let i = 0;
    setInterval(() => {
        gsap.to(rotator, {
            yPercent: -110,
            opacity: 0,
            duration: 0.35,
            ease: 'power2.in',
            onComplete: () => {
                i = (i + 1) % words.length;
                rotator.textContent = words[i];
                gsap.fromTo(
                    rotator,
                    { yPercent: 110, opacity: 0 },
                    { yPercent: 0, opacity: 1, duration: 0.45, ease: 'power2.out' }
                );
            },
        });
    }, 2600);
}

document.querySelectorAll('[data-drag-rail]').forEach((rail) => {
    let isDown = false;
    let startX = 0;
    let startScroll = 0;
    let moved = false;

    rail.addEventListener('pointerdown', (e) => {
        isDown = true;
        moved = false;
        startX = e.clientX;
        startScroll = rail.scrollLeft;
        rail.classList.add('is-dragging');
    });

    window.addEventListener('pointermove', (e) => {
        if (!isDown) return;
        const dx = e.clientX - startX;
        if (Math.abs(dx) > 6) moved = true;
        rail.scrollLeft = startScroll - dx;
    });

    window.addEventListener('pointerup', () => {
        isDown = false;
        rail.classList.remove('is-dragging');
    });

    rail.addEventListener(
        'click',
        (e) => {
            if (moved) {
                e.preventDefault();
                e.stopPropagation();
            }
        },
        true
    );
});