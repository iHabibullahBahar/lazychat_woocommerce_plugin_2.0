#!/bin/bash

# ============================================================================
# LazyChat WordPress SVN Structure Generator
# ============================================================================
# Creates/updates the SVN folder structure for WordPress.org plugin submission
# - trunk/   : Updated with latest plugin code each run
# - tags/    : Auto-appends new versions, preserves old ones
# - assets/  : Preserved (banners, icons, screenshots)
# ============================================================================

# Configuration
PLUGIN_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PLUGIN_NAME="lazychat"
MAIN_FILE="lazychat.php"
README_FILE="README.md"
SVN_DIR="/Users/habib/Documents/Projects/lazy_chat/lazy_inbox/lazychat_wordpress_svn"

# Colors for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

echo ""
echo "🚀 LazyChat SVN Structure Generator"
echo "===================================="
echo ""

# Detect current version from main plugin file
echo "🔍 Detecting current version..."
CURRENT_VERSION=$(grep -E "Version: [0-9]+\.[0-9]+\.[0-9]+" "$PLUGIN_DIR/$MAIN_FILE" | head -1 | sed -E 's/.*Version: ([0-9]+\.[0-9]+\.[0-9]+).*/\1/')

if [ -z "$CURRENT_VERSION" ]; then
    echo -e "${RED}❌ Error: Could not detect current version from $MAIN_FILE${NC}"
    exit 1
fi

echo -e "${BLUE}📌 Current version: $CURRENT_VERSION${NC}"

# Auto-increment version (patch version +1)
echo "� Auto-incrementing version..."
IFS='.' read -r -a VERSION_PARTS <<< "$CURRENT_VERSION"
MAJOR="${VERSION_PARTS[0]}"
MINOR="${VERSION_PARTS[1]}"
PATCH="${VERSION_PARTS[2]}"

# Increment patch version
PATCH=$((PATCH + 1))
NEW_VERSION="$MAJOR.$MINOR.$PATCH"

echo -e "${GREEN}📌 New version: $NEW_VERSION${NC}"
echo ""

# Update version in main plugin file
echo "✏️  Updating version in files..."

# Update Version: comment line in main file
sed -i '' "s/Version: $CURRENT_VERSION/Version: $NEW_VERSION/g" "$PLUGIN_DIR/$MAIN_FILE"

# Update LAZYCHAT_VERSION constant in main file
sed -i '' "s/define('LAZYCHAT_VERSION', '$CURRENT_VERSION')/define('LAZYCHAT_VERSION', '$NEW_VERSION')/g" "$PLUGIN_DIR/$MAIN_FILE"

# Update Stable tag in README.md
if [ -f "$PLUGIN_DIR/$README_FILE" ]; then
    sed -i '' "s/Stable tag: $CURRENT_VERSION/Stable tag: $NEW_VERSION/g" "$PLUGIN_DIR/$README_FILE"
    echo -e "${GREEN}   ✓ Updated $MAIN_FILE and $README_FILE to v$NEW_VERSION${NC}"
else
    echo -e "${GREEN}   ✓ Updated $MAIN_FILE to v$NEW_VERSION${NC}"
fi

# Git commit and push
echo ""
echo "📝 Committing changes to git..."
cd "$PLUGIN_DIR"
git add .

if git diff --cached --quiet; then
    echo -e "${YELLOW}   ⚠ No changes to commit${NC}"
else
    git commit -m "Bump version to $NEW_VERSION" > /dev/null 2>&1
    echo -e "${GREEN}   ✓ Changes committed${NC}"
fi

echo "🔄 Pushing to remote repository..."
if git push origin main > /dev/null 2>&1; then
    echo -e "${GREEN}   ✓ Pushed to GitHub${NC}"
else
    echo -e "${YELLOW}   ⚠ Could not push to remote (continuing anyway)${NC}"
fi
echo ""

# Create base SVN structure if it doesn't exist
echo "📂 Setting up SVN directory structure..."

# Create main SVN directory
mkdir -p "$SVN_DIR"

# Create trunk directory (will be cleared and updated)
mkdir -p "$SVN_DIR/trunk"

# Create tags and assets directories ONLY if they don't exist (preserve them)
if [ ! -d "$SVN_DIR/tags" ]; then
    mkdir -p "$SVN_DIR/tags"
    echo -e "${GREEN}   ✓ Created tags/ directory${NC}"
else
    echo -e "${BLUE}   ✓ Preserved existing tags/ directory${NC}"
fi

if [ ! -d "$SVN_DIR/assets" ]; then
    mkdir -p "$SVN_DIR/assets"
    echo -e "${GREEN}   ✓ Created assets/ directory${NC}"
    
    # Create a README for assets folder with instructions
    cat > "$SVN_DIR/assets/README.txt" << 'EOF'
WordPress.org Plugin Assets - LazyChat
========================================

Required assets for your plugin page on WordPress.org

ICONS (Required)
----------------
icon-128x128.png     Standard icon (128x128 pixels)
icon-256x256.png     Retina icon (256x256 pixels)
icon.svg             Optional SVG version

BANNERS (Required)
------------------
banner-772x250.png   Standard banner
banner-1544x500.png  Retina banner (2x)

SCREENSHOTS (Optional but recommended)
--------------------------------------
screenshot-1.png     Matches "1." in readme.txt Screenshots section
screenshot-2.png     Matches "2." in readme.txt Screenshots section
(add more as needed)

FILE REQUIREMENTS
-----------------
- Format: PNG or JPG (PNG recommended for icons)
- Icons: Square, transparent background works best
- Banners: No important content in outer 10% (may be cropped)
- Screenshots: Any reasonable size, will be resized automatically

Note: Assets folder is separate from plugin code.
Changes here only affect your WordPress.org plugin page.
EOF
    echo -e "${GREEN}   ✓ Created assets/README.txt with instructions${NC}"
else
    echo -e "${BLUE}   ✓ Preserved existing assets/ directory${NC}"
fi

echo ""

# Clear and update trunk with latest plugin code
echo "🔄 Updating trunk/ with latest plugin code..."

# Remove old trunk contents
rm -rf "$SVN_DIR/trunk"/*

# Copy plugin files to trunk, excluding development files
rsync -av \
    --exclude='.git' \
    --exclude='.gitignore' \
    --exclude='*.sh' \
    --exclude='.DS_Store' \
    --exclude='docs/' \
    --exclude='tests/' \
    --exclude='node_modules/' \
    --exclude='vendor/' \
    --exclude='.phpcs.xml' \
    --exclude='phpunit.xml' \
    --exclude='composer.json' \
    --exclude='composer.lock' \
    --exclude='package.json' \
    --exclude='package-lock.json' \
    --exclude='.editorconfig' \
    --exclude='.eslintrc' \
    --exclude='.stylelintrc' \
    --exclude='Gruntfile.js' \
    --exclude='gulpfile.js' \
    --exclude='webpack.config.js' \
    --exclude='.github/' \
    --exclude='.vscode/' \
    --exclude='.idea/' \
    "$PLUGIN_DIR/" "$SVN_DIR/trunk/" > /dev/null 2>&1

# Rename README.md to readme.txt for WordPress.org if it exists
if [ -f "$SVN_DIR/trunk/README.md" ]; then
    mv "$SVN_DIR/trunk/README.md" "$SVN_DIR/trunk/readme.txt"
    echo -e "${GREEN}   ✓ Renamed README.md to readme.txt${NC}"
fi

# Clean up .DS_Store files from trunk (macOS junk)
find "$SVN_DIR/trunk" -name ".DS_Store" -type f -delete 2>/dev/null
echo -e "${GREEN}   ✓ Cleaned .DS_Store files${NC}"

echo -e "${GREEN}   ✓ trunk/ updated successfully${NC}"
echo ""

# Auto-create version tag (always creates since version is new)
TAG_DIR="$SVN_DIR/tags/$NEW_VERSION"
echo "🏷️  Creating tag for v$NEW_VERSION..."
cp -r "$SVN_DIR/trunk" "$TAG_DIR"
echo -e "${GREEN}   ✓ Created tags/$NEW_VERSION/${NC}"
echo ""

# List existing tags
EXISTING_TAGS=$(ls -1 "$SVN_DIR/tags" 2>/dev/null | grep -E '^[0-9]+\.[0-9]+\.[0-9]+$' | sort -V)
TAG_COUNT=$(echo "$EXISTING_TAGS" | grep -c . 2>/dev/null || echo "0")

# Summary
echo "===================================="
echo -e "${GREEN}✅ SVN structure ready!${NC}"
echo ""
echo -e "${BLUE}📍 Location: $SVN_DIR${NC}"
echo ""
echo "Structure:"
echo "  trunk/   - v$NEW_VERSION (latest)"
echo "  assets/  - Plugin page assets"
echo ""
echo -e "  tags/    - ${CYAN}$TAG_COUNT version(s)${NC}"
if [ -n "$EXISTING_TAGS" ]; then
    echo "$EXISTING_TAGS" | while read tag; do
        if [ "$tag" = "$NEW_VERSION" ]; then
            echo -e "             └─ ${GREEN}$tag (current)${NC}"
        else
            echo "             └─ $tag"
        fi
    done
fi
echo ""
echo -e "${YELLOW}📝 Next steps:${NC}"
echo "   1. Add assets (banners, icons) to assets/"
echo "   2. Commit to WordPress.org SVN:"
echo -e "      ${CYAN}cd $SVN_DIR${NC}"
echo -e "      ${CYAN}svn add trunk/* --force${NC}"
echo -e "      ${CYAN}svn add tags/$NEW_VERSION --force${NC}"
echo -e "      ${CYAN}svn commit -m \"Release v$NEW_VERSION\"${NC}"
echo ""

# Open destination folder
echo "📂 Opening SVN folder..."
open "$SVN_DIR"

echo -e "${GREEN}🎉 Done!${NC}"
echo ""
