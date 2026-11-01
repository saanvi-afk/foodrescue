# Bundled dependencies

Composer pins exact PHP dependencies in `composer.lock`. Their source/notice files are installed in `vendor/`. Direct dependencies are PHPMailer (email), Endroid QR Code (PNG tokens), and Dompdf (PDF reports); transitive packages are recorded in the lockfile.

Frontend browser libraries are bundled locally, with adjacent license notices in `public/assets/vendor/`:

- Leaflet 1.9.4 — BSD-2-Clause, https://leafletjs.com/
- Chart.js 4.5.1 — MIT, https://www.chartjs.org/
- jsQR 1.4.0 — Apache-2.0, https://github.com/cozmo/jsQR

OpenStreetMap tiles are fetched at runtime and retain attribution. No maps or libraries require frontend secrets. Camera permission is requested only when the user chooses QR scanning.
