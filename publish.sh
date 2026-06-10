#!/bin/bash

# Usage: ./publish.sh [major|minor|patch]

VERSION_TYPE=$1

if [[ -z "$VERSION_TYPE" ]]; then
  echo "Please provide a version type (major, minor, patch)"
  exit 1
fi

# Check for uncommitted changes
if [[ -n "$(git status --porcelain)" ]]; then
  echo "Uncommitted changes found. Commit or stash your changes before tagging."
  exit 1
fi

# Get the latest version tag by semver sort (not commit date)
LATEST_TAG=$(git tag --sort=-v:refname | head -1)

if [[ -z "$LATEST_TAG" ]]; then
  echo "No existing tags found"
  exit 1
fi

# Strip 'v' prefix for version arithmetic, re-add after
PREFIX=""
VERSION="$LATEST_TAG"
if [[ "$LATEST_TAG" == v* ]]; then
  PREFIX="v"
  VERSION="${LATEST_TAG#v}"
fi

# Increment version using semantic versioning
NEW_VERSION=$(php -r "
  list(\$major, \$minor, \$patch) = explode('.', '$VERSION');
  switch ('$VERSION_TYPE') {
    case 'major': \$major++; \$minor = 0; \$patch = 0; break;
    case 'minor': \$minor++; \$patch = 0; break;
    case 'patch': \$patch++; break;
    default: exit(1);
  }
  echo '${PREFIX}' . \$major . '.' . \$minor . '.' . \$patch;
")

if [[ -z "$NEW_VERSION" ]]; then
  echo "Invalid version type"
  exit 1
fi

# Prevent duplicate tags
if git rev-parse "$NEW_VERSION" >/dev/null 2>&1; then
  echo "Tag $NEW_VERSION already exists"
  exit 1
fi

# Tag the new version and push to remote
git tag "$NEW_VERSION"
git push origin main --tags || { echo "Push failed. Removing local tag $NEW_VERSION"; git tag -d "$NEW_VERSION"; exit 1; }

echo "Tagged and pushed version $NEW_VERSION"
