import { createApp } from 'vue';
import OAuthAuthorize from '@/components/oauth/OAuthAuthorize.vue';

const mountEl = document.getElementById('oauth-authorize-app');
const propsEl = document.getElementById('oauth-authorize-props');

if (mountEl && propsEl?.textContent) {
    createApp(OAuthAuthorize, JSON.parse(propsEl.textContent)).mount(mountEl);
}
