export async function loadDrivingRoute(points) {
    if (points.length < 2) {
        return null;
    }

    const coordinates = points
        .map((point) => `${point.lng},${point.lat}`)
        .join(';');

    const url = `https://router.project-osrm.org/route/v1/driving/${coordinates}?overview=full&geometries=geojson`;
    const response = await fetch(url);

    if (!response.ok) {
        throw new Error('Route service unavailable');
    }

    const data = await response.json();
    return data.routes?.[0]?.geometry ?? null;
}