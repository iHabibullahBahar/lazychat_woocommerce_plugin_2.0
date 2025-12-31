#!/bin/bash

# Configuration
PLUGIN_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PLUGIN_NAME="lazychat"
MAIN_FILE="lazychat.php"
DESTINATION_DIR="/Users/habib/Documents/woo_pluggin"

# Colors for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo "🚀 Creating LazyChat Plugin ZIP..."
echo ""

# Detect current version
echo "🔍 Detecting current version..."
CURRENT_VERSION=$(grep -E "Version: [0-9]+\.[0-9]+\.[0-9]+" "$PLUGIN_DIR/$MAIN_FILE" | head -1 | sed -E 's/.*Version: ([0-9]+\.[0-9]+\.[0-9]+).*/\1/')

if [ -z "$CURRENT_VERSION" ]; then
    echo "❌ Error: Could not detect current version"
    exit 1
fi

echo -e "${BLUE}📌 Current version: $CURRENT_VERSION${NC}"

# Increment version
echo "🔢 Auto-incrementing version..."
IFS='.' read -r -a VERSION_PARTS <<< "$CURRENT_VERSION"
MAJOR="${VERSION_PARTS[0]}"
MINOR="${VERSION_PARTS[1]}"
PATCH="${VERSION_PARTS[2]}"

# Increment patch version
PATCH=$((PATCH + 1))
NEW_VERSION="$MAJOR.$MINOR.$PATCH"

echo -e "${BLUE}📌 New version: $NEW_VERSION${NC}"

# Update version in main file
echo "✏️  Updating version in $MAIN_FILE..."

# Update Version: comment line
sed -i '' "s/Version: $CURRENT_VERSION/Version: $NEW_VERSION/g" "$PLUGIN_DIR/$MAIN_FILE"

# Update LAZYCHAT_VERSION constant
sed -i '' "s/define('LAZYCHAT_VERSION', '$CURRENT_VERSION')/define('LAZYCHAT_VERSION', '$NEW_VERSION')/g" "$PLUGIN_DIR/$MAIN_FILE"

# Update Stable tag in README.md
README_FILE="README.md"
if [ -f "$PLUGIN_DIR/$README_FILE" ]; then
    echo "✏️  Updating Stable tag in $README_FILE..."
    sed -i '' "s/Stable tag: $CURRENT_VERSION/Stable tag: $NEW_VERSION/g" "$PLUGIN_DIR/$README_FILE"
fi

echo -e "${GREEN}✅ Version updated successfully!${NC}"
echo ""

# Git commit and push
echo "📝 Committing changes to git..."
git add .

if git diff --cached --quiet; then
    echo -e "${YELLOW}⚠️  No changes to commit${NC}"
else
    git commit -m "Bump version to $NEW_VERSION" > /dev/null 2>&1
    echo -e "${GREEN}✅ Changes committed${NC}"
fi

echo "🔄 Pushing to remote repository..."
if git push origin main > /dev/null 2>&1; then
    echo -e "${GREEN}✅ Pushed to GitHub successfully!${NC}"
else
    echo -e "${YELLOW}⚠️  Warning: Could not push to remote (continuing anyway)${NC}"
fi
echo ""

# Create destination directory if it doesn't exist
mkdir -p "$DESTINATION_DIR"

# Create temporary directory for plugin files
TEMP_DIR=$(mktemp -d)
PLUGIN_TEMP_DIR="$TEMP_DIR/$PLUGIN_NAME"

# Copy plugin files to temp directory, excluding development files
# WordPress.org requires: README.md (or readme.txt), main plugin file, includes, assets
# Exclude: .git, .gitignore, shell scripts, docs/, tests/, node_modules/, vendor/
echo "📦 Creating ZIP file..."
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
    "$PLUGIN_DIR/" "$PLUGIN_TEMP_DIR/" > /dev/null 2>&1

# Create ZIP file
ZIP_FILE="$DESTINATION_DIR/$PLUGIN_NAME-$NEW_VERSION.zip"
cd "$TEMP_DIR" || exit
zip -r "$ZIP_FILE" "$PLUGIN_NAME" > /dev/null 2>&1

# Clean up temp directory
rm -rf "$TEMP_DIR"

# Check if ZIP was created successfully
if [ -f "$ZIP_FILE" ]; then
    echo ""
    echo -e "${GREEN}✅ SUCCESS! ZIP file created:${NC}"
    echo -e "${BLUE}📍 Location: $ZIP_FILE${NC}"
    echo ""
    
    # Get file size
    FILE_SIZE=$(du -h "$ZIP_FILE" | cut -f1)
    echo -e "${BLUE}📊 File size: $FILE_SIZE${NC}"
    echo ""
    
    # Open destination folder
    echo "📂 Opening destination folder..."
    open "$DESTINATION_DIR"
    echo ""
    
    echo -e "${GREEN}🎉 All done! Your plugin is ready for submission.${NC}"
    echo ""
else
    echo -e "${YELLOW}❌ Error: Failed to create ZIP file${NC}"
    exit 1
fi
