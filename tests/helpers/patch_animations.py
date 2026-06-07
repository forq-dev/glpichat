import re

file_path = "/home/h1d4n/Documents/dev-glpi/glpi/plugins/glpichat/public/css/glpichat.css"
with open(file_path, "r") as f:
    content = f.read()

# Replace animation definitions for enter
content = re.sub(
    r"animation:\s*(glpichat-enter-(?:br|bl|tr|tl)|glpichat-panel-enter)\s+200ms\s+cubic-bezier\(0\.16,\s*1,\s*0\.3,\s*1\)",
    r"animation: \1 400ms cubic-bezier(0.34, 1.56, 0.64, 1)",
    content
)

# Replace animation definitions for exit
content = re.sub(
    r"animation:\s*(glpichat-exit-(?:br|bl|tr|tl)|glpichat-panel-exit(?:-top)?)\s+150ms\s+cubic-bezier\(0\.16,\s*1,\s*0\.3,\s*1\)",
    r"animation: \1 200ms cubic-bezier(0.4, 0, 0.2, 1)",
    content
)

# Replace width transitions (is-closing, etc)
content = re.sub(
    r"transition-duration:\s*150ms",
    r"transition-duration: 200ms",
    content
)
content = re.sub(
    r"transition-timing-function:\s*cubic-bezier\(0\.16,\s*1,\s*0\.3,\s*1\)",
    r"transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1)",
    content
)

# Now, update keyframes
def update_keyframes(content, direction, t_enter, t_exit):
    # Enter
    content = re.sub(
        r"(@keyframes\s+glpichat-enter-" + direction + r"\s*\{\s*from\s*\{[^}]*?)transform:\s*scale\(0\.96\)\s*translate\([^)]+\);",
        r"\1transform: scale(0.90) " + t_enter + r";\n    filter: blur(4px);",
        content, flags=re.DOTALL
    )
    content = re.sub(
        r"(@keyframes\s+glpichat-enter-" + direction + r"\s*\{[^}]*?to\s*\{[^}]*?)transform:\s*scale\(1\)\s*translate\([^)]+\);",
        r"\1transform: scale(1) translate(0, 0);\n    filter: blur(0px);",
        content, flags=re.DOTALL
    )
    # Exit
    content = re.sub(
        r"(@keyframes\s+glpichat-exit-" + direction + r"\s*\{\s*from\s*\{[^}]*?)transform:\s*scale\(1\)\s*translate\([^)]+\);",
        r"\1transform: scale(1) translate(0, 0);\n    filter: blur(0px);",
        content, flags=re.DOTALL
    )
    content = re.sub(
        r"(@keyframes\s+glpichat-exit-" + direction + r"\s*\{[^}]*?to\s*\{[^}]*?)transform:\s*scale\(0\.96\)\s*translate\([^)]+\);",
        r"\1transform: scale(0.95) " + t_exit + r";\n    filter: blur(4px);",
        content, flags=re.DOTALL
    )
    return content

content = update_keyframes(content, "br", "translate(12px, 12px)", "translate(8px, 8px)")
content = update_keyframes(content, "bl", "translate(-12px, 12px)", "translate(-8px, 8px)")
content = update_keyframes(content, "tr", "translate(12px, -12px)", "translate(8px, -8px)")
content = update_keyframes(content, "tl", "translate(-12px, -12px)", "translate(-8px, -8px)")

# Update glpichat-panel-enter and exit
content = re.sub(
    r"(@keyframes\s+glpichat-panel-enter\s*\{\s*from\s*\{[^}]*?)transform:\s*scale\(0\.96\)\s*translateY\(8px\);",
    r"\1transform: scale(0.90) translateY(16px);\n    filter: blur(4px);",
    content, flags=re.DOTALL
)
content = re.sub(
    r"(@keyframes\s+glpichat-panel-enter\s*\{[^}]*to\s*\{[^}]*)transform:\s*scale\(1\)\s*translateY\(0\);",
    r"\1transform: scale(1) translateY(0);\n    filter: blur(0px);",
    content, flags=re.DOTALL
)

content = re.sub(
    r"(@keyframes\s+glpichat-panel-exit\s*\{\s*from\s*\{[^}]*?)transform:\s*scale\(1\)\s*translateY\(0\);",
    r"\1transform: scale(1) translateY(0);\n    filter: blur(0px);",
    content, flags=re.DOTALL
)
content = re.sub(
    r"(@keyframes\s+glpichat-panel-exit\s*\{[^}]*to\s*\{[^}]*)transform:\s*scale\(0\.96\)\s*translateY\(8px\);",
    r"\1transform: scale(0.95) translateY(8px);\n    filter: blur(4px);",
    content, flags=re.DOTALL
)

with open(file_path, "w") as f:
    f.write(content)

print("Animations patched!")
