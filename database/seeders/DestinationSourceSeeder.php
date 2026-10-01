<?php

namespace Database\Seeders;

use App\Models\Destination;
use App\Models\DestinationSource;
use Illuminate\Database\Seeder;

class DestinationSourceSeeder extends Seeder
{
    private const UNCITABLE_HOSTS = [
        'en.wikipedia.org',
        'en.m.wikipedia.org',
    ];

    public function run(): void
    {
        $sources = [
            // ==================== MANILA / NCR ====================
            [
                'destination_slug' => 'fort-santiago',
                'source_name' => 'Intramuros Administration',
                'source_url' => 'https://intramuros.gov.ph/fs/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'rizal-park',
                'source_name' => 'National Parks Development Committee',
                'source_url' => 'https://npdc.gov.ph/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'national-museum-of-the-philippines',
                'source_name' => 'National Museum of the Philippines',
                'source_url' => 'https://www.nationalmuseum.gov.ph/',
                'source_type' => 'official_site',
            ],
            [
                'destination_slug' => 'la-mesa-eco-park',
                'source_name' => 'La Mesa Ecopark (MWSS)',
                'source_url' => 'https://www.mwss.gov.ph/la-mesa-ecopark/',
                'source_type' => 'official_lgu',
            ],

            // ==================== BAGUIO / CORDILLERA ====================
            [
                'destination_slug' => 'baguio-botanical-garden',
                'source_name' => 'Baguio City Tourism',
                'source_url' => 'https://visita.baguio.gov.ph/',
                'details_url' => 'https://visita.baguio.gov.ph/park-tickets/29',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'burnham-park',
                'source_name' => 'Baguio City Tourism',
                'source_url' => 'https://main.baguio.gov.ph/tourism/RVpjAz10/burnham-park',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'camp-john-hay',
                'source_name' => 'Camp John Hay (BCDA)',
                'source_url' => 'https://campjohnhay.ph/',
                'source_type' => 'official_site',
            ],
            [
                'destination_slug' => 'wright-park',
                'source_name' => 'Baguio City Tourism',
                'source_url' => 'https://visita.baguio.gov.ph/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'mirador-heritage-and-eco-park',
                'source_name' => 'Mirador Heritage and Eco Park',
                'source_url' => 'https://mirador.eco/',
                'source_type' => 'official_site',
            ],
            [
                'destination_slug' => 'bencab-museum',
                'source_name' => 'BenCab Museum',
                'source_url' => 'https://bencabmuseum.org/',
                'details_url' => 'https://bencabmuseum.org/location-info/',
                'source_type' => 'official_site',
            ],
            [
                'destination_slug' => 'banaue-rice-terraces',
                'source_name' => 'Ifugao Provincial Government',
                'source_url' => 'https://ifugao.gov.ph/banaue/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'batad-rice-terraces',
                'source_name' => 'Ifugao Provincial Government',
                'source_url' => 'https://ifugao.gov.ph/banaue/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'sagada',
                'source_name' => 'Sagada Tourism',
                'source_url' => 'https://tourism.sagada.gov.ph/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'sagada-sumaguing-cave',
                'source_name' => 'Sagada Tourism',
                'source_url' => 'https://tourism.sagada.gov.ph/sagada-tourist-sites/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'kiltepan-viewpoint',
                'source_name' => 'Sagada Tourism',
                'source_url' => 'https://tourism.sagada.gov.ph/sagada-tourist-sites/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'mt-pulag',
                'source_name' => 'DENR CAR / Mt. Pulag National Park',
                'source_url' => 'https://car.denr.gov.ph/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'mt-ulap',
                'source_name' => 'Itogon LGU',
                'source_url' => 'https://itogon.gov.ph/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'chico-river',
                'source_name' => 'Kalinga Provincial Government',
                'source_url' => 'https://kalingaprovince.gov.ph/kalinga-tourism/',
                'source_type' => 'official_lgu',
            ],

            // ==================== ILOCOS REGION ====================
            [
                'destination_slug' => 'calle-crisologo',
                'source_name' => 'Vigan City Government',
                'source_url' => 'https://www.vigancity.gov.ph/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'vigan-heritage-site',
                'source_name' => 'Vigan City Government',
                'source_url' => 'https://www.vigancity.gov.ph/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'bangui-wind-farm',
                'source_name' => 'Ilocos Norte Tourism',
                'source_url' => 'https://ilocosnorte.ph/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'bantay-abot-cave',
                'source_name' => 'Ilocos Norte Tourism',
                'source_url' => 'https://ilocosnorte.ph/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'cape-bojeador-lighthouse',
                'source_name' => 'Ilocos Norte Tourism',
                'source_url' => 'https://ilocosnorte.ph/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'kapurpurawan-rock-formations',
                'source_name' => 'Ilocos Norte Tourism',
                'source_url' => 'https://ilocosnorte.ph/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'paoay-sand-dunes-adventures',
                'source_name' => 'Ilocos Norte Tourism',
                'source_url' => 'https://ilocosnorte.ph/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'san-agustin-church-of-paoay',
                'source_name' => 'Ilocos Norte Tourism',
                'source_url' => 'https://ilocosnorte.ph/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'hundred-islands-national-park',
                'source_name' => 'Alaminos City Tourism',
                'source_url' => 'https://www.alaminoscity.gov.ph/i-choose-hundred-islands/hundred-islands-national-park.html',
                'source_type' => 'official_lgu',
            ],

            // ==================== CENTRAL LUZON / CAGAYAN VALLEY ====================
            [
                'destination_slug' => 'clark-freeport-zone',
                'source_name' => 'Visit Clark (CDC)',
                'source_url' => 'http://www.visitclark.com/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'mt-pinatubo',
                'source_name' => 'Capas Tourism',
                'source_url' => 'https://capas.gov.ph/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'zoobic-safari',
                'source_name' => 'Zoobic Safari',
                'source_url' => 'https://zoobic.com.ph/',
                'source_type' => 'official_site',
            ],
            [
                'destination_slug' => 'las-casas-filipinas-de-acuzar',
                'source_name' => 'Las Casas Filipinas de Acuzar',
                'source_url' => 'https://lascasasfilipinas.com/',
                'source_type' => 'official_site',
            ],
            [
                'destination_slug' => 'minalungao-national-park',
                'source_name' => 'General Tinio LGU',
                'source_url' => 'https://generaltinio.gov.ph/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'minor-basilica-of-our-lady-of-piat',
                'source_name' => 'Piat LGU',
                'source_url' => 'https://piat.gov.ph/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'callao-cave',
                'source_name' => 'Peñablanca LGU',
                'source_url' => 'https://penablanca.gov.ph/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'palaui-island',
                'source_name' => 'Santa Ana LGU',
                'source_url' => 'https://santaana.gov.ph/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'cape-engano-lighthouse',
                'source_name' => 'Santa Ana LGU',
                'source_url' => 'https://santaana.gov.ph/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'aguinaldo-shrine',
                'source_name' => 'Kawit LGU',
                'source_url' => 'https://kawit.gov.ph/',
                'source_type' => 'official_lgu',
            ],

            // ==================== CALABARZON / RIZAL / LAGUNA ====================
            [
                'destination_slug' => 'taal-basilica',
                'source_name' => 'Taal LGU',
                'source_url' => 'https://taal.gov.ph/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'taal-heritage-town',
                'source_name' => 'Taal LGU',
                'source_url' => 'https://taal.gov.ph/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'taal-volcano-view',
                'source_name' => 'Tagaytay City Tourism',
                'source_url' => 'https://tagaytay.gov.ph/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'tagaytay-picnic-grove',
                'source_name' => 'Tagaytay City Tourism',
                'source_url' => 'https://tagaytay.gov.ph/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'mt-batulao',
                'source_name' => 'Nasugbu LGU',
                'source_url' => 'https://nasugbu.gov.ph/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'masungi-georeserve',
                'source_name' => 'Masungi Georeserve',
                'source_url' => 'https://www.masungigeoreserve.com/',
                'source_type' => 'official_site',
            ],
            [
                'destination_slug' => 'daranak-falls',
                'source_name' => 'Tanay LGU',
                'source_url' => 'https://tanay.gov.ph/for-visitors/tourist-information/local-attractions/daranak-falls',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'tinipak-river-and-rock-formation',
                'source_name' => 'Tanay LGU',
                'source_url' => 'https://www.tanay.gov.ph/for-visitors/tourist-information/local-attractions/tinipak-rock',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'mt-daraitan',
                'source_name' => 'Tanay LGU',
                'source_url' => 'https://tanay.gov.ph/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'hinulugang-taktak',
                'source_name' => 'Antipolo City Tourism',
                'source_url' => 'https://antipolo.gov.ph/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'cloud-9-antipolo',
                'source_name' => 'Cloud 9 Antipolo',
                'source_url' => 'https://cloud9antipolo.com/',
                'source_type' => 'official_site',
            ],
            [
                'destination_slug' => 'pinto-art-museum',
                'source_name' => 'Pinto Art Museum',
                'source_url' => 'https://pintoart.org/',
                'source_type' => 'official_site',
            ],
            [
                'destination_slug' => 'caliraya-lake',
                'source_name' => 'Lumban LGU',
                'source_url' => 'https://lumban.gov.ph/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'hulugan-falls',
                'source_name' => 'Luisiana LGU',
                'source_url' => 'https://luisiana.gov.ph/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'seven-lakes-of-san-pablo',
                'source_name' => 'San Pablo City Tourism',
                'source_url' => 'https://sanpablocity.gov.ph/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'ipo-dam-view',
                'source_name' => 'Norzagaray LGU',
                'source_url' => 'https://norzagaray.gov.ph/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'sierra-madre-mountain-view',
                'source_name' => 'Tanay LGU',
                'source_url' => 'https://tanay.gov.ph/',
                'source_type' => 'official_lgu',
            ],

            // ==================== AURORA / QUEZON / BICOL ====================
            [
                'destination_slug' => 'baler-hanging-bridge',
                'source_name' => 'Baler Tourism',
                'source_url' => 'https://www.aurora.ph/baler.html',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'baler-lighthouse',
                'source_name' => 'Baler Tourism',
                'source_url' => 'https://www.aurora.ph/baler.html',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'museo-de-baler',
                'source_name' => 'National Museum of the Philippines',
                'source_url' => 'https://www.nationalmuseum.gov.ph/',
                'source_type' => 'official_site',
            ],
            [
                'destination_slug' => 'sabang-beach',
                'source_name' => 'Baler Tourism',
                'source_url' => 'https://www.aurora.ph/baler.html',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'dicasalarin-cove',
                'source_name' => 'Baler Tourism',
                'source_url' => 'https://www.aurora.ph/baler.html',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'quezon-protected-landscape',
                'source_name' => 'DENR CALABARZON',
                'source_url' => 'https://calabarzon.denr.gov.ph/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'mayon-volcano-natural-park',
                'source_name' => 'Province of Albay',
                'source_url' => 'https://albay.gov.ph/tourist-spot/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'mayon-skydrive-atv-adventure',
                'source_name' => 'Mayon SkyDrive ATV',
                'source_url' => 'https://www.mayonskydrive.com/',
                'source_type' => 'official_site',
            ],

            // ==================== ZAMBALES ====================
            [
                'destination_slug' => 'anawangin-cove',
                'source_name' => 'San Antonio LGU',
                'source_url' => 'https://sanantoniozambales.gov.ph/',
                'source_type' => 'official_lgu',
            ],
            [
                'destination_slug' => 'nagsasa-cove',
                'source_name' => 'San Antonio LGU',
                'source_url' => 'https://sanantoniozambales.gov.ph/',
                'source_type' => 'official_lgu',
            ],

            // ==================== LA UNION ====================
            [
                'destination_slug' => 'san-juan-la-union',
                'source_name' => 'San Juan LGU',
                'source_url' => 'https://sanjuanlaunion.gov.ph/',
                'source_type' => 'official_lgu',
            ],
        ];

        $created = 0;
        $skipped = 0;

        foreach ($sources as $source) {
            $destination = Destination::where(
                'slug',
                $source['destination_slug']
            )->first();

            if (!$destination) {
                $this->command?->warn(
                    'Destination not found: '
                    . $source['destination_slug']
                );

                $skipped++;

                continue;
            }

            DestinationSource::updateOrCreate(
                [
                    'destination_id' => $destination->id,
                    'source_url' => $source['source_url'],
                ],
                [
                    'source_name' => $source['source_name'],
                    'source_type' => $source['source_type'],
                    'details_url' => $source['details_url']
                        ?? $this->citableUrl($destination),
                    'allowed_by_policy' => false,
                    'status' => 'pending',
                    'crawl_delay_seconds' => 10,
                    'robots_status' => null,
                    'robots_content' => null,
                    'robots_checked_at' => null,
                    'fetchability' => null,
                    'fetchability_checked_at' => null,
                    'fetchability_note' => null,
                    'last_checked_at' => null,
                    'last_success_at' => null,
                    'etag' => null,
                    'last_modified' => null,
                    'error_message' => null,
                ]
            );

            $created++;
        }

        $this->command?->info(
            "Registered {$created} destination sources."
        );

        $this->command?->warn(
            "Skipped {$skipped} sources because their destinations were not found."
        );
    }

    private function citableUrl(Destination $destination): ?string
    {
        $url = trim((string) $destination->hours_source_url);

        if ($url === '') {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (in_array($host, self::UNCITABLE_HOSTS, true)) {
            return null;
        }

        return $url;
    }
}