const assetVersion = new URL(import.meta.url).searchParams.get('v') || 'dev';

import('./main.js?v=' + encodeURIComponent(assetVersion));
