import http from 'k6/http';
import {check} from 'k6';

export const options = {
    vus: 1,
    iterations: 1,
    thresholds: {
        http_req_failed: ['rate==0'],
        http_req_duration: ['p(95)<500'],
        data_received: ['count<1048576'],
    },
};

export default function () {
    const assetUrl = __ENV.ASSET_URL || '';
    if (!assetUrl) throw new Error('ASSET_URL is required and should reference a hashed production asset.');
    const first = http.get(assetUrl, {headers: {'Accept-Encoding': 'gzip, br'}});
    check(first, {
        'asset returns 200': ({status}) => status === 200,
        'asset is immutable': ({headers}) => /immutable/i.test(headers['Cache-Control'] || ''),
        'asset cache is one year': ({headers}) => /max-age=31536000/i.test(headers['Cache-Control'] || ''),
        'asset has validators': ({headers}) => Boolean(headers.ETag || headers['Last-Modified']),
    });
    const validators = {};
    if (first.headers.ETag) validators['If-None-Match'] = first.headers.ETag;
    if (first.headers['Last-Modified']) validators['If-Modified-Since'] = first.headers['Last-Modified'];
    const second = http.get(assetUrl, {headers: validators});
    check(second, {'conditional request hits validator': ({status}) => status === 304});
}
