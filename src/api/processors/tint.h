#pragma once

#include "processors/base.h"

#include <vector>

namespace weserv {
namespace api {
namespace processors {

class Tint : ImageProcessor {
 public:
    using ImageProcessor::ImageProcessor;

    VImage process(const VImage &image) const override;
};

}  // namespace processors
}  // namespace api
}  // namespace weserv
