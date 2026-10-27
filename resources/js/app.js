import Alpine from 'alpinejs';
import './swipe';
import 'leaflet/dist/leaflet.css';
import L from 'leaflet';
import { loadDrivingRoute } from './routing';
import initBudgetTier from './admin-budget-tier';
import destinationStage from './stage';
import './home';

window.Alpine = Alpine;
window.L = L;

/*
 * BEFORE Alpine.start(), always.
 *
 * An `Alpine.data()` registration that lands after start() leaves every `x-data`
 * on the page unevaluated. The destinations stage renders from the server, so it
 * still looks like a stage -- with its panels, its caption and its dots all
 * present -- while the script that moves them is silently never attached. That is
 * indistinguishable from a frozen stage, and it reports nothing anywhere.
 */
Alpine.data('destinationStage', destinationStage);

Alpine.start();

import markerIcon from 'leaflet/dist/images/marker-icon.png';
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png';
import markerShadow from 'leaflet/dist/images/marker-shadow.png';

L.Icon.Default.mergeOptions({
    iconUrl: markerIcon,
    iconRetinaUrl: markerIcon2x,
    shadowUrl: markerShadow,
});

const destinationMap = document.querySelector('[data-destination-map]');

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js');
    });
}

/*
 * Admin-only, so it no-ops everywhere else rather than needing a guard at the
 * call site. It reads the tier boundaries out of the rendered guideline instead
 * of carrying its own numbers, which is why it has to run after the DOM exists.
 */
initBudgetTier();

if (destinationMap) {
    const lat = Number(destinationMap.dataset.lat);
    const lng = Number(destinationMap.dataset.lng);
    const name = destinationMap.dataset.name;

    const map = L.map(destinationMap).setView([lat, lng], 13);

    window.L.mapInstance = map;

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map);

    L.marker([lat, lng])
        .addTo(map)
        .bindPopup(name)
        .openPopup();
}

/*
 * One colour per day, cycling past eight. Chosen dark enough that the white
 * pin numeral and the route line both read on the light OSM tiles; day one
 * stays in the teal family for brand continuity.
 */
const DAY_COLORS = [
    '#007F73', '#C05621', '#6B46C1', '#2B6CB0',
    '#B83280', '#2F855A', '#B7791F', '#4A5568',
];

const dayColor = (day) => DAY_COLORS[(day - 1) % DAY_COLORS.length];

function drawDayRoute(map, geometry, stops, color) {
    if (geometry) {
        L.geoJSON(geometry, {
            style: {
                color,
                weight: 4,
                opacity: 0.9,
                lineCap: 'round',
                lineJoin: 'round',
            },
        }).addTo(map);
    } else {
        L.polyline(stops, {
            color,
            weight: 3,
            opacity: 0.75,
            dashArray: '6 8',
        }).addTo(map);
    }
}

const itineraryMap = document.querySelector('[data-itinerary-points]');

if (itineraryMap) {
    const points = JSON.parse(
        itineraryMap.dataset.itineraryPoints
    );

    const map = L.map(itineraryMap);

    window.L.mapInstance = map;

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map);

    if (points.length === 0) {
        map.setView([14.5995, 120.9842], 6);
    } else {
        points.forEach((point) => {
            L.marker([point.lat, point.lng], {
                icon: L.divIcon({
                    className: 'tm-map-pin-wrap',
                    html: `<span class="tm-map-pin" style="background-color:${dayColor(point.day)}">${point.day}</span>`,
                    iconSize: [26, 26],
                    iconAnchor: [13, 13],
                    popupAnchor: [0, -15],
                }),
            })
                .addTo(map)
                .bindPopup(`Day ${point.day}: ${point.name}`);
        });

        const latLngs = points.map((point) => [
            point.lat,
            point.lng,
        ]);

        if (latLngs.length === 1) {
            map.setView(latLngs[0], 13);
        } else {
            map.fitBounds(latLngs, {
                padding: [30, 30],
            });
        }

        const byDay = new Map();
        points.forEach((point) => {
            const group = byDay.get(point.day) ?? [];
            group.push(point);
            byDay.set(point.day, group);
        });

        byDay.forEach((group, day) => {
            // A hotel's check-in, nights and check-out share one pin; zero-length
            // legs between them tell the road nothing.
            const stops = group.filter((point, index) =>
                index === 0
                || point.lat !== group[index - 1].lat
                || point.lng !== group[index - 1].lng
            );

            if (stops.length < 2) {
                return;
            }

            const dayLatLngs = stops.map((point) => [
                point.lat,
                point.lng,
            ]);
            const color = dayColor(day);

            loadDrivingRoute(stops)
                .then((geometry) => drawDayRoute(map, geometry, dayLatLngs, color))
                .catch(() => drawDayRoute(map, null, dayLatLngs, color));
        });
    }
}