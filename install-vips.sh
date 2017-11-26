#!/bin/bash 

version_full=$VIPS_VERSION_MAJOR.$VIPS_VERSION_MINOR.$VIPS_VERSION_MICRO
version_suffix=$VIPS_VERSION_SUFFIX
vips_tarball=https://github.com/jcupitt/libvips/releases/download/v$version_full${version_suffix:+-$version_suffix}/vips-$version_full-3.tar.gz

set -e

# do we already have the correct vips built? early exit if yes
# we could check the configure params as well I guess
if [ -d "$HOME/vips/bin" ]; then
	installed_version=$($HOME/vips/bin/vips --version)
	escaped_version="$VIPS_VERSION_MAJOR\.$VIPS_VERSION_MINOR\.$VIPS_VERSION_MICRO"
	echo "Need vips-$version_full"
	echo "Found $installed_version"
	if [[ "$installed_version" =~ ^vips-$escaped_version ]]; then
		echo "Using cached directory"
		exit 0
	fi
fi

rm -rf $HOME/vips
wget $vips_tarball
tar xf vips-$version_full-3.tar.gz
cd vips-$version_full
CXXFLAGS=-D_GLIBCXX_USE_CXX11_ABI=0 ./configure --prefix=$HOME/vips $*
make && make install
