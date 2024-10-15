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

# Get the latest version tag from git
LATEST_TAG=$(git describe --tags $(git rev-list --tags --max-count=1))

# Increment version using semantic versioning
NEW_VERSION=$(php -r "
  list(\$major, \$minor, \$patch) = explode('.', '$LATEST_TAG');
  switch ('$VERSION_TYPE') {
    case 'major': \$major++; \$minor = 0; \$patch = 0; break;
    case 'minor': \$minor++; \$patch = 0; break;
    case 'patch': \$patch++; break;
    default: exit(1);
  }
  echo \$major . '.' . \$minor . '.' . \$patch;
")

if [[ -z "$NEW_VERSION" ]]; then
  echo "Invalid version type"
  exit 1
fi

# Tag the new version and push to remote
git tag $NEW_VERSION
git push origin $NEW_VERSION

echo "Tagged and pushed version $NEW_VERSION"
