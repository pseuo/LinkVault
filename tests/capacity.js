import http from 'k6/http';
import {check} from 'k6';
import {Rate} from 'k6/metrics';

const redirectFailures = new Rate('redirect_failures');

export const options = {
    scenarios: {
        redirects: {
            executor: 'constant-arrival-rate',
            rate: Number(__ENV.RPS || 100),
            timeUnit: '1s',
            duration: __ENV.DURATION || '2m',
            preAllocatedVUs: Number(__ENV.PREALLOCATED_VUS || 100),
            maxVUs: Number(__ENV.MAX_VUS || 500),
        },
    },
    thresholds: {
        http_req_duration: ['p(95)<500'],
        redirect_failures: ['rate<0.01'],
        dropped_iterations: ['count==0'],
    },
};

export default function () {
    const baseUrl = (__ENV.BASE_URL || '').replace(/\/$/, '');
    const slug = __ENV.SLUG || '';
    if (!baseUrl || !slug) throw new Error('BASE_URL and SLUG are required.');
    const response = http.get(`${baseUrl}/${encodeURIComponent(slug)}`, {redirects: 0, tags: {name: 'linkvault_redirect'}});
    const passed = check(response, {'returns 302': ({status}) => status === 302});
    redirectFailures.add(!passed);
}
