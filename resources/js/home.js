import Lenis from 'lenis';
import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

gsap.registerPlugin(ScrollTrigger);

const lenis = new Lenis({
    lerp: 0.075,
    wheelMultiplier: 0.9,
    touchMultiplier: 1.4,
    smoothWheel: true,
    respectReducedMotion: false,
});

window.lenis = lenis;

lenis.on('scroll', ScrollTrigger.update);

gsap.ticker.add((time) => {
    lenis.raf(time * 1000);
});

gsap.ticker.lagSmoothing(0);

document.addEventListener('click', (event) => {
    const link = event.target.closest('a[href^="#"]');

    if (!link) {
        return;
    }

    const href = link.getAttribute('href');

    if (!href || href.length < 2) {
        return;
    }

    event.preventDefault();

    if (href === '#top' || href === '#') {
        lenis.scrollTo(0, {
            duration: 1.6,
            easing: (value) => 1 - Math.pow(1 - value, 4),
        });

        return;
    }

    const target = document.querySelector(href);

    if (!target) {
        return;
    }

    lenis.scrollTo(target, {
        duration: 1.6,
        easing: (value) => 1 - Math.pow(1 - value, 4),
    });
});

const paintBackground = (color) => {
    gsap.to('body', {
        backgroundColor: color,
        duration: 0.9,
        ease: 'power2.out',
        overwrite: 'auto',
    });
};

document.querySelectorAll('[data-bg]').forEach((section) => {
    const color = section.dataset.bg;

    ScrollTrigger.create({
        trigger: section,
        start: 'top 60%',
        end: 'bottom 60%',
        onEnter: () => paintBackground(color),
        onEnterBack: () => paintBackground(color),
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

gsap.utils.toArray('[data-reveal]').forEach((element) => {
    gsap.fromTo(
        element,
        { y: 48, opacity: 0 },
        {
            y: 0,
            opacity: 1,
            duration: 1.1,
            ease: 'power3.out',
            delay: parseFloat(element.dataset.revealDelay || 0),
            scrollTrigger: {
                trigger: element,
                start: 'top 85%',
            },
        }
    );
});

gsap.utils.toArray('[data-line]').forEach((element) => {
    gsap.fromTo(
        element,
        {
            scaleX: 0,
            transformOrigin: 'left center',
        },
        {
            scaleX: 1,
            duration: 1.2,
            ease: 'power3.inOut',
            scrollTrigger: {
                trigger: element,
                start: 'top 90%',
            },
        }
    );
});

gsap.utils.toArray('[data-parallax]').forEach((element) => {
    const speed = parseFloat(element.dataset.parallax || 8);

    gsap.fromTo(
        element,
        { yPercent: speed },
        {
            yPercent: -speed,
            ease: 'none',
            scrollTrigger: {
                trigger: element.closest('section') || element,
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
    const movers = Array.from(hero.querySelectorAll('[data-depth]')).map((element) => ({
        depth: parseFloat(element.dataset.depth),
        setX: gsap.quickTo(element, 'x', {
            duration: 0.9,
            ease: 'power2.out',
        }),
        setY: gsap.quickTo(element, 'y', {
            duration: 0.9,
            ease: 'power2.out',
        }),
    }));

    hero.addEventListener('pointermove', (event) => {
        const x = event.clientX / window.innerWidth - 0.5;
        const y = event.clientY / window.innerHeight - 0.5;

        movers.forEach(({ depth, setX, setY }) => {
            setX(x * 40 * depth);
            setY(y * 40 * depth);
        });
    });
}

const rotator = document.querySelector('[data-rotator]');

if (rotator) {
    const words = JSON.parse(rotator.dataset.words);
    let index = 0;

    window.setInterval(() => {
        gsap.to(rotator, {
            yPercent: -110,
            opacity: 0,
            duration: 0.35,
            ease: 'power2.in',
            onComplete: () => {
                index = (index + 1) % words.length;
                rotator.textContent = words[index];

                gsap.fromTo(
                    rotator,
                    { yPercent: 110, opacity: 0 },
                    {
                        yPercent: 0,
                        opacity: 1,
                        duration: 0.45,
                        ease: 'power2.out',
                    }
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

    rail.addEventListener('pointerdown', (event) => {
        isDown = true;
        moved = false;
        startX = event.clientX;
        startScroll = rail.scrollLeft;
        rail.classList.add('is-dragging');
    });

    window.addEventListener('pointermove', (event) => {
        if (!isDown) {
            return;
        }

        const distance = event.clientX - startX;

        if (Math.abs(distance) > 6) {
            moved = true;
        }

        rail.scrollLeft = startScroll - distance;
    });

    window.addEventListener('pointerup', () => {
        isDown = false;
        rail.classList.remove('is-dragging');
    });

    rail.addEventListener(
        'click',
        (event) => {
            if (moved) {
                event.preventDefault();
                event.stopPropagation();
            }
        },
        true
    );
});

const overlay = document.getElementById('intro-overlay');

let introPlayed = false;

try {
    introPlayed = sessionStorage.getItem('introPlayed') === '1';
} catch {
    
}

if (overlay && !introPlayed) {
    overlay.dataset.introPlaying = 'true';
    overlay.style.opacity = '1';

    lenis.stop();

    gsap.set('.intro-fade', {
        opacity: 0,
        y: 18,
    });

    gsap.set('.intro-badge', {
        opacity: 0,
        scale: 0.85,
    });

    gsap.set('.intro-side', {
        opacity: 0,
    });

    gsap.set('.intro-portal', {
        clipPath: 'ellipse(0% 0% at 50% 100%)',
    });

    gsap.set(overlay, {
        clipPath: 'circle(150% at 50% 50%)',
    });

    const timeline = gsap.timeline({
        defaults: {
            ease: 'power3.out',
        },
        onComplete: () => {
            gsap.killTweensOf([
                '.intro-badge-ring',
                '.intro-sun',
            ]);

            try {
                sessionStorage.setItem('introPlayed', '1');
            } catch {
                
            }

            overlay.remove();
            lenis.start();
            ScrollTrigger.refresh();
        },

    });

    timeline
        .to(
            '.intro-fade',
            {
                opacity: 1,
                y: 0,
                duration: 0.9,
                stagger: 0.14,
            },
            0
        )
        .to(
            '.intro-side',
            {
                opacity: 1,
                duration: 0.8,
            },
            0.4
        )
        .to({}, { duration: 0.4 })
        .to(
            '.intro-portal',
            {
                clipPath: 'ellipse(160% 160% at 50% 100%)',
                duration: 1.5,
                ease: 'power3.inOut',
            },
            1.4
        )
        .to(
            '.intro-script',
            {
                color: '#FFF3D6',
                duration: 1.5,
                ease: 'power2.inOut',
            },
            '<'
        )
        .to(
            '.intro-badge',
            {
                opacity: 1,
                scale: 1,
                duration: 0.7,
            },
            2.3
        )
        .to({}, { duration: 0.5 })
        .to(
            '.intro-night',
            {
                opacity: 1,
                duration: 1,
                ease: 'power2.inOut',
            },
            3.1
        )
        .to(
            '.intro-script',
            {
                color: '#F4A259',
                duration: 1,
                ease: 'power2.inOut',
            },
            '<'
        )
        .to(
            '.intro-ui, .intro-badge, .intro-side',
            {
                opacity: 0,
                y: -16,
                duration: 0.6,
                ease: 'power2.in',
            },
            3.25
        )
        .to(
            overlay,
            {
                clipPath: 'circle(0% at 50% 50%)',
                duration: 1.1,
                ease: 'power3.inOut',
            },
            3.5
        );

    gsap.to('.intro-badge-ring', {
        rotation: 360,
        duration: 14,
        repeat: -1,
        ease: 'none',
        transformOrigin: '50% 50%',
    });

    gsap.to('.intro-sun', {
        scale: 1.12,
        duration: 2.4,
        yoyo: true,
        repeat: -1,
        ease: 'sine.inOut',
    });
} else if (overlay) {
    overlay.remove();
}
