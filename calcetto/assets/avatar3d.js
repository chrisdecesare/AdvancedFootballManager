/*
 * Avatar 3D del Personaggio (avatar.php) e vista campo a grandezza naturale della Home (index.php, toggle accanto al campo 2D).
 * Usa Three.js via CDN (import map in avatar.php/index.php, nessun build step: vedi calcetto/lib/formation.php:render_pitch_3d()).
 *
 * Il corpo è procedurale (capsule + box colorati) finché il giocatore non collega un avatar Ready Player Me (RPM): a quel punto
 * si carica il suo .glb con GLTFLoader al posto del corpo di base. Vestiti (maglia/pantaloncini/scarpe) sono sempre le mesh
 * procedurali colorate con i dati del negozio (lib/shop.php): un vero RPM ha già i suoi vestiti, ma qui restano i nostri sopra
 * per dare comunque un riscontro visivo immediato dell'equip, anche prima che arrivino asset 3D dedicati per ogni capo.
 */
import * as THREE from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';

const RPM_SUBDOMAIN = 'https://demo.readyplayer.me/avatar?frameApi';

/** Costruisce un omino a capsule colorate: testa, busto (maglia), bacino/gambe (pantaloncini), piedi (scarpe). */
function buildPlaceholderBody(colors) {
    const group = new THREE.Group();
    const skin = new THREE.MeshStandardMaterial({ color: 0xe0b98a, roughness: 0.7 });
    const head = new THREE.Mesh(new THREE.SphereGeometry(0.14, 20, 16), skin);
    head.position.y = 1.62;
    group.add(head);

    const jerseyMat = new THREE.MeshStandardMaterial({ color: colors.jersey?.a || '#2a3f9b', roughness: 0.6 });
    const torso = new THREE.Mesh(new THREE.CapsuleGeometry(0.19, 0.5, 4, 12), jerseyMat);
    torso.position.y = 1.2;
    group.add(torso);

    const sleeveMat = new THREE.MeshStandardMaterial({ color: colors.jersey?.b || '#ffffff', roughness: 0.6 });
    for (const side of [-1, 1]) {
        const arm = new THREE.Mesh(new THREE.CapsuleGeometry(0.055, 0.42, 4, 8), side < 0 ? jerseyMat : sleeveMat);
        arm.position.set(0.27 * side, 1.22, 0);
        arm.rotation.z = side * 0.18;
        arm.userData.isArm = true;
        arm.userData.side = side;
        group.add(arm);
    }

    const shortsMat = new THREE.MeshStandardMaterial({ color: colors.shorts || '#ffffff', roughness: 0.6 });
    const shorts = new THREE.Mesh(new THREE.CapsuleGeometry(0.2, 0.18, 4, 12), shortsMat);
    shorts.position.y = 0.82;
    group.add(shorts);

    const legMat = new THREE.MeshStandardMaterial({ color: 0xe0b98a, roughness: 0.7 });
    const shoesMat = new THREE.MeshStandardMaterial({ color: colors.shoes || '#1f1a2e', roughness: 0.4 });
    for (const side of [-1, 1]) {
        const leg = new THREE.Mesh(new THREE.CapsuleGeometry(0.08, 0.55, 4, 8), legMat);
        leg.position.set(0.1 * side, 0.4, 0);
        leg.userData.isLeg = true;
        group.add(leg);
        const shoe = new THREE.Mesh(new THREE.BoxGeometry(0.11, 0.08, 0.22), shoesMat);
        shoe.position.set(0.1 * side, 0.06, 0.04);
        group.add(shoe);
    }

    group.userData.jerseyMat = jerseyMat;
    group.userData.sleeveMat = sleeveMat;
    group.userData.shortsMat = shortsMat;
    group.userData.shoesMat = shoesMat;
    return group;
}

function buildHat(item) {
    if (!item) {
        return null;
    }
    const g = new THREE.Group();
    const colorA = new THREE.Color(item.colors?.a || '#ff5a5f');
    const cap = new THREE.Mesh(new THREE.SphereGeometry(0.15, 16, 12, 0, Math.PI * 2, 0, Math.PI / 2),
        new THREE.MeshStandardMaterial({ color: colorA, roughness: 0.6 }));
    cap.position.y = 1.74;
    g.add(cap);
    return g;
}

/** Scena singola per il riquadro di avatar.php: un avatar al centro, controlli con il mouse/dito. */
function initAvatarStage(el) {
    let cfg;
    try {
        cfg = JSON.parse(el.dataset.avatar3d || '{}');
    } catch (e) {
        cfg = {};
    }
    const scene = new THREE.Scene();
    scene.background = new THREE.Color(0x1a2740);
    const camera = new THREE.PerspectiveCamera(35, el.clientWidth / el.clientHeight || 1, 0.1, 50);
    camera.position.set(0, 1.2, 2.6);

    const renderer = new THREE.WebGLRenderer({ antialias: true });
    renderer.setPixelRatio(Math.min(devicePixelRatio, 2));
    renderer.setSize(el.clientWidth, el.clientHeight);
    el.appendChild(renderer.domElement);

    scene.add(new THREE.HemisphereLight(0xffffff, 0x222233, 1.1));
    const key = new THREE.DirectionalLight(0xffffff, 1.2);
    key.position.set(2, 4, 3);
    scene.add(key);

    const floor = new THREE.Mesh(new THREE.CircleGeometry(1.4, 32), new THREE.MeshStandardMaterial({ color: 0x2fa85b, roughness: 0.9 }));
    floor.rotation.x = -Math.PI / 2;
    scene.add(floor);

    let body = buildPlaceholderBody({ jersey: cfg.jersey, shorts: cfg.shorts, shoes: cfg.shoes });
    scene.add(body);
    let hat = buildHat(cfg.hat);
    if (hat) {
        body.add(hat);
    }

    if (cfg.rpmUrl) {
        new GLTFLoader().load(cfg.rpmUrl, (gltf) => {
            scene.remove(body);
            body = gltf.scene;
            body.scale.setScalar(1);
            scene.add(body);
            if (hat) {
                body.add(hat);
            }
        }, undefined, () => {
            // rete assente o link scaduto: resta il corpo procedurale, nessun blocco della pagina
        });
    }

    const controls = new OrbitControls(camera, renderer.domElement);
    controls.target.set(0, 1.1, 0);
    controls.enablePan = false;
    controls.minDistance = 1.4;
    controls.maxDistance = 4;
    controls.update();

    let celebrating = 0;
    const playBtn = document.querySelector('[data-avatar3d-play]');
    if (playBtn) {
        playBtn.addEventListener('click', () => {
            celebrating = 1;
        });
    }

    const clock = new THREE.Clock();
    (function tick() {
        requestAnimationFrame(tick);
        const t = clock.getElapsedTime();
        if (celebrating) {
            body.position.y = Math.abs(Math.sin(t * 6)) * 0.25;
            body.rotation.y = Math.sin(t * 4) * 0.6;
            if (t % 2 < 0.02) {
                // niente: l'esultanza si "consuma" da sola dopo un giro di orologio breve
            }
        } else {
            body.position.y = 0;
            body.rotation.y += 0.0025;
        }
        renderer.render(scene, camera);
    })();
    setInterval(() => { if (celebrating) celebrating = Math.max(0, celebrating - 1); }, 1500);

    new ResizeObserver(() => {
        const w = el.clientWidth, h = el.clientHeight;
        if (!w || !h) {
            return;
        }
        camera.aspect = w / h;
        camera.updateProjectionMatrix();
        renderer.setSize(w, h);
    }).observe(el);
}

/** Editor Ready Player Me in un <dialog>: al termine manda l'URL del .glb al form nascosto e lo invia. */
function initRpmDialog() {
    const openBtn = document.querySelector('[data-rpm-open]');
    const dialog = document.getElementById('rpm-dialog');
    const frame = document.getElementById('rpm-frame');
    const closeBtn = document.querySelector('[data-rpm-close]');
    if (!openBtn || !dialog || !frame) {
        return;
    }
    openBtn.addEventListener('click', () => {
        frame.src = RPM_SUBDOMAIN;
        dialog.showModal();
    });
    closeBtn?.addEventListener('click', () => dialog.close());
    window.addEventListener('message', (event) => {
        const data = typeof event.data === 'string' ? safeJson(event.data) : event.data;
        if (data?.eventName === 'v1.avatar.exported' && data?.data?.url) {
            document.getElementById('rpm-url').value = data.data.url;
            document.getElementById('rpm-save-form').submit();
        }
    });
}

function safeJson(s) {
    try {
        return JSON.parse(s);
    } catch (e) {
        return null;
    }
}

/** Vista campo della Home (toggle accanto ai cerchi): tanti omini colorati posizionati come i tocchi 2D di render_pitch(). */
function initPitchStage(el) {
    let players;
    try {
        players = JSON.parse(el.dataset.pitch3d || '[]');
    } catch (e) {
        players = [];
    }
    const scene = new THREE.Scene();
    scene.background = new THREE.Color(0x0f2e1c);
    const camera = new THREE.PerspectiveCamera(45, el.clientWidth / el.clientHeight || 1, 0.1, 100);
    camera.position.set(0, 9, 7.5);
    camera.lookAt(0, 0, 0);

    const renderer = new THREE.WebGLRenderer({ antialias: true });
    renderer.setPixelRatio(Math.min(devicePixelRatio, 2));
    renderer.setSize(el.clientWidth, el.clientHeight);
    el.appendChild(renderer.domElement);

    scene.add(new THREE.HemisphereLight(0xffffff, 0x0a3320, 1.2));
    const sun = new THREE.DirectionalLight(0xffffff, 1);
    sun.position.set(3, 8, 4);
    scene.add(sun);

    const FW = 10, FH = 14;
    const pitch = new THREE.Mesh(new THREE.PlaneGeometry(FW, FH), new THREE.MeshStandardMaterial({ color: 0x2f8f4f, roughness: 1 }));
    pitch.rotation.x = -Math.PI / 2;
    scene.add(pitch);
    const lineMat = new THREE.LineBasicMaterial({ color: 0xffffff });
    const border = new THREE.LineLoop(new THREE.EdgesGeometry(new THREE.PlaneGeometry(FW - 0.2, FH - 0.2)), lineMat);
    border.rotation.x = -Math.PI / 2;
    border.position.y = 0.01;
    scene.add(border);

    for (const p of players) {
        const body = buildPlaceholderBody({ jersey: p.jersey, shorts: p.shorts, shoes: p.shoes });
        body.scale.setScalar(1.35);
        // left/top sono percentuali (0-100) come in render_pitch(): si convertono in coordinate del campo 3D
        body.position.x = ((p.left ?? 50) / 100 - 0.5) * FW;
        body.position.z = ((p.top ?? 50) / 100 - 0.5) * FH;
        body.lookAt(body.position.x, 0, body.position.z - 1);
        scene.add(body);
    }

    const controls = new OrbitControls(camera, renderer.domElement);
    controls.target.set(0, 0.5, 0);
    controls.maxPolarAngle = Math.PI / 2.1;
    controls.minDistance = 4;
    controls.maxDistance = 16;
    controls.update();

    (function tick() {
        requestAnimationFrame(tick);
        renderer.render(scene, camera);
    })();

    new ResizeObserver(() => {
        const w = el.clientWidth, h = el.clientHeight;
        if (!w || !h) {
            return;
        }
        camera.aspect = w / h;
        camera.updateProjectionMatrix();
        renderer.setSize(w, h);
    }).observe(el);
}

document.querySelectorAll('[data-avatar3d]').forEach(initAvatarStage);
initRpmDialog();

/* Toggle della Home: monta la scena 3D solo la prima volta che il campo diventa visibile, non a ogni caricamento pagina. */
const pitch3dEl = document.querySelector('[data-pitch3d]');
if (pitch3dEl) {
    let mounted = false;
    const mountIfVisible = () => {
        if (!mounted && pitch3dEl.offsetParent !== null) {
            mounted = true;
            initPitchStage(pitch3dEl);
        }
    };
    mountIfVisible();
    document.querySelectorAll('[data-pitch-view-toggle]').forEach(btn => btn.addEventListener('click', () => setTimeout(mountIfVisible, 0)));
}
