import http from 'k6/http';
import {check} from 'k6';
import {Rate} from 'k6/metrics';

const failures = new Rate('workload_failures');
const rate = Number(__ENV.RPS || 5);
const scenario = (exec) => ({
    executor: 'constant-arrival-rate', rate, timeUnit: '1s', duration: __ENV.DURATION || '2m',
    preAllocatedVUs: Number(__ENV.PREALLOCATED_VUS || 10), maxVUs: Number(__ENV.MAX_VUS || 50), exec,
});

export const options = {
    scenarios: {
        redirects: scenario('redirect'),
        admin_list: scenario('adminList'),
        search: scenario('search'),
        analytics: scenario('analytics'),
        csv_export: {...scenario('csvExport'), rate: Number(__ENV.EXPORT_RPS || 1)},
    },
    thresholds: {
        'http_req_duration{workload:redirect}': ['p(95)<500', 'p(99)<1000'],
        'http_req_duration{workload:admin_list}': ['p(95)<1000', 'p(99)<2000'],
        'http_req_duration{workload:search}': ['p(95)<1000', 'p(99)<2000'],
        'http_req_duration{workload:analytics}': ['p(95)<5000', 'p(99)<8000'],
        'http_req_duration{workload:csv_export}': ['p(95)<3000', 'p(99)<5000'],
        workload_failures: ['rate<0.01'],
        dropped_iterations: ['count==0'],
    },
};

const baseUrl = (__ENV.BASE_URL || '').replace(/\/$/, '');
const slug = __ENV.SLUG || '';
const cookie = __ENV.ADMIN_COOKIE || '';
const adminParams = (workload) => ({headers: {Cookie: cookie}, redirects: 0, tags: {workload}});
const record = (response, workload, statuses = [200]) => {
    const passed = check(response, {[`${workload} status`]: ({status}) => statuses.includes(status)});
    failures.add(!passed, {workload});
};

export function setup() {
    if (!baseUrl || !slug || !cookie) throw new Error('BASE_URL, SLUG and ADMIN_COOKIE are required.');
}

export function redirect() {
    const response = http.get(`${baseUrl}/${encodeURIComponent(slug)}`, {redirects: 0, tags: {workload: 'redirect'}});
    record(response, 'redirect', [302]);
}

export function adminList() {
    record(http.get(`${baseUrl}/?section=links&page=1`, adminParams('admin_list')), 'admin_list');
}

export function search() {
    record(http.get(`${baseUrl}/?section=links&q=${encodeURIComponent(__ENV.SEARCH || 'benchmark')}`, adminParams('search')), 'search');
}

export function analytics() {
    record(http.get(`${baseUrl}/?section=analytics&range=30&timezone=UTC`, adminParams('analytics')), 'analytics');
}

export function csvExport() {
    record(http.get(`${baseUrl}/export-analytics?report=trend&range=30&timezone=UTC`, adminParams('csv_export')), 'csv_export');
}
