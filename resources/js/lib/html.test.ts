import { describe, expect, it } from 'vitest';
import { escapeHtml } from './html';

describe('escapeHtml', () => {
    it('把 HTML 特殊字元跳脫掉', () => {
        expect(escapeHtml(`Cafe <script>alert("x")</script> & co`)).toBe(
            'Cafe &lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; co',
        );
    });
});
