import Alpine from 'alpinejs';
import './swipe';
import 'leaflet/dist/leaflet.css';
import L from 'leaflet';
import { loadDrivingRoute } from './routing';
import './home';

window.Alpine = Alpine;
window.L = L;

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
        const latLngs = points.map((point) => {
            const marker = L.marker([
                point.lat,
                point.lng,
            ]).addTo(map);

            marker.bindPopup(
                `Day ${point.day}: ${point.name}`
            );

            return [
                point.lat,
                point.lng,
            ];
        });

        if (latLngs.length === 1) {
            map.setView(latLngs[0], 13);
        } else {
            map.fitBounds(latLngs, {
                padding: [30, 30],
            });
        }

        loadDrivingRoute(points)
            .then((geometry) => {
                if (geometry) {
                    L.geoJSON(geometry, {
                        style: {
                            color: '#00A896',
                            weight: 5,
                        },
                    }).addTo(map);
                } else {
                    L.polyline(latLngs, {
                        color: '#00A896',
                        weight: 4,
                        dashArray: '8 8',
                    }).addTo(map);
                }
            })
            .catch(() => {
                L.polyline(latLngs, {
                    color: '#00A896',
                    weight: 4,
                    dashArray: '8 8',
                }).addTo(map);
            });
    }
}