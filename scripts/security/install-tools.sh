#!/bin/sh
set -eu
# Versions stables vérifiées le 28 octobre 2026 auprès des publications officielles.
mkdir -p /tmp/ikoma-security-tools
cd /tmp/ikoma-security-tools
curl -fsSL --retry 3 -O https://github.com/gitleaks/gitleaks/releases/download/v8.30.1/gitleaks_8.30.1_linux_x64.tar.gz
curl -fsSL --retry 3 -O https://github.com/gitleaks/gitleaks/releases/download/v8.30.1/gitleaks_8.30.1_checksums.txt
awk '$2 == "gitleaks_8.30.1_linux_x64.tar.gz" {print}' gitleaks_8.30.1_checksums.txt | sha256sum -c -
tar -xzf gitleaks_8.30.1_linux_x64.tar.gz gitleaks
curl -fsSL --retry 3 -O https://github.com/aquasecurity/trivy/releases/download/v0.76.1/trivy_0.76.1_Linux-64bit.tar.gz
curl -fsSL --retry 3 -O https://github.com/aquasecurity/trivy/releases/download/v0.76.1/trivy_0.76.1_checksums.txt
awk '$2 == "trivy_0.76.1_Linux-64bit.tar.gz" {print}' trivy_0.76.1_checksums.txt | sha256sum -c -
tar -xzf trivy_0.76.1_Linux-64bit.tar.gz trivy
